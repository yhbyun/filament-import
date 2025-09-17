<?php

namespace Konnco\FilamentImport\Actions;

use App\Models\Company;
use App\Services\ExcelImportService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\Concerns\CanCustomizeProcess;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Konnco\FilamentImport\Concerns\HasActionMutation;
use Konnco\FilamentImport\Concerns\HasActionUniqueField;
use Konnco\FilamentImport\Concerns\HasTemporaryDisk;
use Konnco\FilamentImport\Concerns\HasValidation;
use Konnco\FilamentImport\ImportColumn;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Maatwebsite\Excel\Concerns\Importable;
use RuntimeException;

class ImportAction extends Action
{
    use CanCustomizeProcess;
    use HasActionMutation;
    use HasActionUniqueField;
    use HasTemporaryDisk;
    use HasValidation;
    use Importable;

    protected array $fields = [];

    protected bool $shouldSkipHeader = true;

    protected bool $shouldMassCreate = true;

    protected bool $shouldHandleBlankRows = true;

    protected array $cachedHeadingOptions = [];

    protected ?Closure $handleRecordCreation = null;

    /** @var array<ImportColumn> */
    protected array $importColumns = [];

    public $importResults = [];

    protected array $excelHeaders = [];

    protected ?Collection $excelCollection = null;

    public static function getDefaultName(): ?string
    {
        return 'import';
    }

    public function collection(Collection $collection): void
    {
        $this->excelCollection = $collection;
    }

    public function getCollection($livewire): Collection
    {
        if (empty($this->excelCollection)) {
            $this->excelCollection = $this->toCollection($livewire->uploadedFile, $this->temporaryDiskIsRemote() ? $this->getTemporaryDisk() : null)->first();
        }

        return $this->excelCollection;
    }

    public function headers($livewire, array $headers): void
    {
        $this->excelHeaders = $headers;
        $livewire->headers = $headers;
    }

    public function getHeaders(): array
    {
        return $this->excelHeaders;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (): string => __('filament-import::actions.import'));

        $this->setInitialForm();

        $this->button();

        $this->groupedIcon('heroicon-s-plus');

        $this->icon('heroicon-o-arrow-up-tray');

        $this->modalHeading('Excel 가져오기');

        $this->modalWidth('4xl');

        $this->modalContent(view('filament-import::components.excel-import-modal'));

        $this->modalSubmitAction(false);
        $this->modalCancelAction(false);
        $this->modalCloseButton(false);
        $this->closeModalByClickingAway(false);
        $this->closeModalByEscaping(false);

        $this->modalFooterActionsAlignment(Alignment::End);

        $this->extraModalFooterActions([
            Action::make('cancel')
                ->label('취소')
                ->color('gray')
                ->action(function ($livewire) {
                    $this->resetImportData($livewire);
                    $livewire->dispatch('import-completed');

                    return false;
                }),

            Action::make('next')
                ->label('다음')
                ->color('primary')
                ->action(function ($livewire, array $data) {
                    $this->handleNext($livewire, $data);
                })
                ->visible(fn ($livewire) => $livewire->currentStep == 1 && empty($livewire->validationResults)),

            Action::make('validate')
                ->label('데이터 검증')
                ->color('primary')
                ->action(function ($livewire) {
                    $this->handleValidation($livewire);
                })
                ->visible(fn ($livewire) => $livewire->currentStep == 2),

            Action::make('import')
                ->label('일괄 등록')
                ->color('success')
                ->action(function ($livewire) {
                    $this->handleImport($livewire);

                    $livewire->dispatch('import-completed');
                })
                ->visible(function ($livewire) {
                    $validationResults = Session::get('validation_results', []);
                    if (! empty($validationResults)) {
                        $summary = app(ExcelImportService::class)->getValidationSummary($validationResults);
                    }

                    return $livewire->currentStep == 3 && ($summary['valid'] ?? 0);
                }),

            Action::make('back')
                ->label('이전')
                ->color('gray')
                ->action(function ($livewire) {
                    $livewire->currentStep--;
                })
                ->visible(fn ($livewire) => $livewire->currentStep > 1 && $livewire->currentStep < 3),
        ]);
    }

    public function setInitialForm(): void
    {
        $this->form($this->getInitialFormSchema());
    }

    public function massCreate($shouldMassCreate = true): static
    {
        $this->shouldMassCreate = $shouldMassCreate;

        return $this;
    }

    public function handleBlankRows($shouldHandleBlankRows = false): static
    {
        $this->shouldHandleBlankRows = $shouldHandleBlankRows;

        return $this;
    }

    public function importColumns(array $columns): static
    {
        logger('importColumns');
        $this->importColumns = collect($columns)->mapWithKeys(fn ($item) => [$item->getName() => $item])->toArray();

        return $this;
    }

    public function guessMatchingColumns($livewire): void
    {
        logger('guessMatchingColumns');

        foreach ($this->importColumns as $key => $column) {
            $index = $this->guessMatchingColumn($livewire, $column);
            if (is_null($index)) {
                throw new RuntimeException("No matching column for $key");
            }

            $column->index($index);
        }

        if (empty($livewire->importColumns)) {
            $livewire->importColumns = collect($this->importColumns)->map(function ($item) {
                return [
                    'name' => $item->getName(),
                    'label' => $item->getLabel(),
                    'index' => $item->getIndex(),
                    'rules' => $item->getValidationRules(),
                ];
            })->toArray();
        }
    }

    public function guessMatchingColumn($livewire, ImportColumn $column)
    {
        $collection = $this->getCollection($livewire);

        $headers = $collection->first()->map('trim')->toArray();
        $this->headers($livewire, $headers);

        $selected = array_search($column->getName(), $headers);
        if ($selected !== false) {
            return $selected;
        } elseif (! empty($column->getAlternativeColumnNames())) {
            $alternativeNames = array_intersect($column->getAlternativeColumnNames(), $headers);
            if (count($alternativeNames) > 0) {
                return array_search(current($alternativeNames), $headers);
            }
        }
    }

    protected function getInitialFormSchema(): array
    {
        return [
            $this->buildFileUpload(),
            Hidden::make('fileRealPath'),
        ];
    }

    protected function buildFileUpload(): FileUpload
    {
        return FileUpload::make('file')
            ->label('')
            ->required(! app()->environment('testing'))
            ->acceptedFileTypes(config('filament-import.accepted_mimes'))
            ->maxSize(10240) // 10MB
            ->live()
            ->disk($this->getTemporaryDisk())
            ->directory($this->getTemporaryDirectory())
            ->visible(fn ($livewire) => $livewire->currentStep == 1)
            ->afterStateUpdated(function (callable $set, TemporaryUploadedFile $state, $livewire) {
                logger('afterStateUpdated fileupload');

                if ($state) {
                    $livewire->uploadedFile = $state->getRealPath();
                    $set('fileRealPath', $state->getRealPath());

                    $this->parseFile($livewire);

                    $livewire->currentStep = 2;
                }
            });
    }

    public function handleRecordCreation(Closure $closure): static
    {
        $this->handleRecordCreation = $closure;
        $this->massCreate(false);

        return $this;
    }

    public function handleNext($livewire, array $data)
    {
        logger('handleNext');

        // Check if file exists in the data
        if (! isset($data['file']) || empty($data['file'])) {
            Notification::make()
                ->title('오류')
                ->body('Excel 파일을 업로드해주세요.')
                ->danger()
                ->send();

            return;
        }

        $file = $data['file'];
        if (is_array($file)) {
            $file = $file[0] ?? null;
        }

        if (! $file) {
            Notification::make()
                ->title('오류')
                ->body('유효한 Excel 파일을 선택해주세요.')
                ->danger()
                ->send();

            return;
        }

        $this->parseFile($livewire);
    }

    protected function parseFile($livewire): void
    {
        logger('parseFile');
        $this->guessMatchingColumns($livewire);

        $collection = $this->getCollection($livewire);
        $collection = $collection->skip((int) $this->shouldSkipHeader);

        $livewire->validationResults = $this->parseExcelFile($livewire);

        if (empty($livewire->validationResults)) {
            Notification::make()
                ->title('오류')
                ->body('Excel 파일에서 데이터를 찾을 수 없습니다.')
                ->danger()
                ->send();
            $livewire->currentStep = 1;
        }
    }

    protected function parseExcelFile($livewire): array
    {
        $collection = $this->getCollection($livewire);
        $collection = $collection->skip((int) $this->shouldSkipHeader);

        return $collection->map(function ($row, $index) {
            if (! $this->shouldHandleBlankRows || ! $row->filter()->isEmpty()) {
                return [
                    'row' => $index + 1,
                    'items' => $row,
                    'is_valid' => false,
                    'errors' => [],
                ];
            } else {
                return null;
            }
        })->filter()->toArray();
    }

    protected function handleValidation($livewire): void
    {
        logger('handleValidateion');

        $this->guessMatchingColumns($livewire);

        $livewire->validationResults = $this->validateData($livewire, $livewire->validationResults);

        Session::put('validation_results', $livewire->validationResults);
        $livewire->currentStep = 3;
    }

    protected function validateData($livewire, array $data): array
    {
        foreach ($data as &$row) {
            $rules = [];
            $columnValues = [];

            foreach ($livewire->importColumns as $key => $column) {
                $columnValue = $row['items'][$column['index']];
                $columnValue = $this->importColumns[$key]->doMutateBeforeCreate($columnValue);

                $columnValues[$key] = $columnValue;

                $rules[$key] = $column['rules'];
            }

            // importColumns에 정의된 순서대로 저장
            // 형대는 [key1 => value1, key2 => value2]
            $row['items'] = $columnValues;

            $validator = Validator::make($columnValues, $rules);

            if ($validator->fails()) {
                $row['is_valid'] = false;
                $row['errors'] = $validator->errors()->all();
            } else {
                // Check for duplicate company name in database
                $existingCompany = Company::where('name', $row['items']['name'])->first();
                if ($existingCompany) {
                    $row['is_valid'] = false;
                    $row['errors'] = ['이미 존재하는 회사명입니다.'];
                } else {
                    $row['is_valid'] = true;
                    $row['errors'] = [];
                }
            }
        }

        return $data;
    }

    protected function handleImport($livewire): void
    {
        $validationResults = Session::get('validation_results', []);

        if (empty($validationResults)) {
            Notification::make()
                ->title('오류')
                ->body('검증 결과를 찾을 수 없습니다. 처음부터 다시 시도해주세요.')
                ->danger()
                ->send();

            $this->resetImportData($livewire);

            return;
        }

        $importResults = $this->importValidData($validationResults);

        $successCount = $importResults['success_count'];
        $errorCount = $importResults['error_count'];

        Notification::make()
            ->title('가져오기 완료')
            ->body("성공적으로 {$successCount}개의 회사를 가져왔습니다.".
                ($errorCount > 0 ? " {$errorCount}개의 레코드가 실패했습니다." : ''))
            ->success()
            ->send();

        $this->resetImportData($livewire);
    }

    protected function resetImportData($livewire): void
    {
        $livewire->currentStep = 1;
        $livewire->uploadedFile = null;
        $livewire->validationResults = [];
        $this->importResults = [];
        $this->formData = [];
        Session::forget('validation_results');
    }

    protected function importValidData(array $validatedData): array
    {
        $successCount = 0;
        $errorCount = 0;
        $errors = [];

        foreach ($validatedData as $row) {
            if ($row['is_valid']) {
                try {
                    // Check again for duplicates (in case of concurrent access)
                    $existingCompany = Company::where('name', $row['items']['name'])->first();
                    if (! $existingCompany) {
                        Company::create([
                            'name' => $row['items']['name'],
                            'representative' => $row['items']['representative'],
                        ]);
                        $successCount++;
                    } else {
                        $errorCount++;
                        $errors[] = "Row {$row['row']}: Company already exists";
                    }
                } catch (\Exception $e) {
                    logger($e->getMessage());
                    $errorCount++;
                    $errors[] = "Row {$row['row']}: ".$e->getMessage();
                }
            } else {
                $errorCount++;
                $errors[] = "Row {$row['row']}: ".implode(', ', $row['errors']);
            }
        }

        return [
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'errors' => $errors,
        ];
    }
}
