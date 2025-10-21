<?php

namespace Konnco\FilamentImport\Actions;

use App\Models\Company;
use App\Services\Migration\DataNormalizer;
use App\Utils\NetHelper;
use App\Utils\SearchHelper;
use Closure;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\Concerns\CanCustomizeProcess;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Konnco\FilamentImport\Concerns\HasActionMutation;
use Konnco\FilamentImport\Concerns\HasActionUniqueField;
use Konnco\FilamentImport\Concerns\HasTemporaryDisk;
use Konnco\FilamentImport\Concerns\HasValidation;
use Konnco\FilamentImport\ImportColumn;
use Konnco\FilamentImport\Utils;
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
                ->label('데이터 정제 및 검증')
                ->color('primary')
                ->action(function ($livewire) {
                    $this->handleValidation($livewire);
                })
                ->visible(fn ($livewire) => $livewire->currentStep == 2)
                ->extraAttributes([
                    'wire:loading.attr' => 'disabled',
                    'wire:loading.class' => 'opacity-50 cursor-wait',
                ])
                ->before(fn ($livewire) => $livewire->dispatch('action-loading'))
                ->after(fn ($livewire) => $livewire->dispatch('action-loaded')),

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
                        $summary = Utils::getValidationSummary($validationResults);
                    }

                    return $livewire->currentStep == 3 && ($summary['valid'] ?? 0);
                })
                ->extraAttributes([
                    'wire:loading.attr' => 'disabled',
                    'wire:loading.class' => 'opacity-50 cursor-wait',
                ])
                ->before(fn ($livewire) => $livewire->dispatch('action-loading'))
                ->after(fn ($livewire) => $livewire->dispatch('action-loaded')),

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
        $this->importColumns = collect($columns)->mapWithKeys(fn ($item) => [$item->getName() => $item])->toArray();

        return $this;
    }

    public function guessMatchingColumns($livewire): void
    {
        foreach ($this->importColumns as $key => $column) {
            $index = $this->guessMatchingColumn($livewire, $column);
            if (is_null($index)) {
                throw new RuntimeException("{$key}에 해당하는 컬럼이 없습니다.");
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

        $headers = $collection->first()->map(fn ($value) => trim($value ?? ''))->toArray();
        $this->headers($livewire, $headers);

        $selected = array_search($column->getName(), $headers);
        if ($selected !== false) {
            return $selected;
        } elseif (! empty($column->getAlternativeColumnNames())) {
            $alternativeNames = $column->getAlternativeColumnNames();

            if (is_array($alternativeNames)) {
                // TODO: 배열에서 'name:2' 형태 지원
                $alternativeNames = array_intersect($alternativeNames, $headers);
                if (count($alternativeNames) > 0) {
                    return array_search(current($alternativeNames), $headers);
                }
            } else {
                if (preg_match('/^([^\:]+):(\d+)$/', $alternativeNames, $matches)) {
                    $name = $matches[1];
                    $pos = $matches[2];

                    return $this->findNthMatchIndex($headers, $name, $pos);
                } else {
                    $result = array_search($alternativeNames, $headers);

                    return $result === false ? null : $result;
                }
            }
        }
    }

    protected function findNthMatchIndex(array $arr, $value, int $n): ?int
    {
        if ($n <= 0) {
            return null;
        }

        $foundCount = 0;
        foreach ($arr as $index => $item) {
            if ($item === $value) {
                $foundCount++;
                if ($foundCount === $n) {
                    return $index;
                }
            }
        }

        return null;
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
            ->label('📁 Excel 파일')
            // ->placeholder(new HtmlString('
            //     <div class="flex flex-col items-center py-8">
            //         <svg class="w-12 h-12 text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            //             <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
            //         </svg>
            //         <span class="text-sm text-gray-600">Excel 파일을 업로드하세요</span>
            //     </div>
            // '))
            ->required(! app()->environment('testing'))
            ->acceptedFileTypes(config('filament-import.accepted_mimes'))
            ->maxSize(10240) // 10MB
            ->live()
            ->disk($this->getTemporaryDisk())
            ->directory($this->getTemporaryDirectory())
            ->visible(fn ($livewire) => $livewire->currentStep == 1)
            ->afterStateUpdated(function (callable $set, TemporaryUploadedFile $state, $livewire) {
                if ($state) {
                    $livewire->uploadedFile = $state->getRealPath();
                    $set('fileRealPath', $state->getRealPath());

                    if ($this->parseFile($livewire)) {
                        $livewire->currentStep = 2;
                    }
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

    protected function parseFile($livewire): bool
    {
        try {
            $this->guessMatchingColumns($livewire);
        } catch (Exception $e) {
            Notification::make()
                ->title('오류')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $livewire->currentStep = 1;

            return false;
        }

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

            return false;
        }

        return true;
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
        $this->guessMatchingColumns($livewire);

        $livewire->validationResults = $this->validateData($livewire, $livewire->validationResults);

        Session::put('validation_results', $livewire->validationResults);
        $livewire->currentStep = 3;
    }

    protected function validateData($livewire, array $data): array
    {
        $validPrevCompany = false;

        // TODO: 하드 코딩 수정
        foreach ($data as &$row) {
            $rules = [];
            $attributes = [];
            $columnValues = [];

            foreach ($livewire->importColumns as $key => $column) {
                $columnValue = $row['items'][$column['index']];
                $columnValue = DataNormalizer::normalizeText($columnValue);
                $columnValue = $this->importColumns[$key]->doMutateBeforeCreate($columnValue, $row['items']);

                $columnValues[$key] = $columnValue;

                $rules[$key] = $column['rules'];
                $attributes[$key] = $column['label'];
                if (str_starts_with($key, 'contact_')) {
                    $attributes[$key] = '담당자 '.$attributes[$key];
                }
            }

            // importColumns에 정의된 순서대로 저장된 상태
            // 형태는 [key1 => value1, key2 => value2]
            $row['items'] = $columnValues;

            $validator = Validator::make($columnValues, $rules, attributes: $attributes);

            if ($validator->fails()) {
                $row['is_valid'] = false;
                $row['errors'] = $validator->errors()->all();
                $validPrevCompany = false;

                logger()->info(($row['items']['name_kr'] ?? $row['items']['name_en']).' validation failed: '.json_encode($row['errors'], JSON_UNESCAPED_UNICODE));

                continue;
            }

            $isDuplicate = $this->checkCompanyDuplicate($row['items']);
            if ($isDuplicate !== false) {
                $row['is_valid'] = false;
                $validPrevCompany = false;

                switch ($isDuplicate) {
                    case 'name_kr':
                        $row['errors'] = ['이미 존재하는 업체명입니다.'];
                        break;

                    case 'phone':
                        $row['errors'] = ['중복 전화번호입니다.'];
                        break;

                    case 'website':
                        $row['errors'] = ['중복 홈페이지입니다.'];
                        break;
                }

                continue;
            }

            if (filled($row['items']['name_kr']) || filled($row['items']['name_en'])) {
                $row['is_valid'] = true;
                $row['errors'] = [];
                $validPrevCompany = true;
            } elseif (filled($row['items']['contact_name'])) {
                if ($validPrevCompany) {
                    $row['is_valid'] = true;
                    $row['errors'] = [];
                } else {
                    $row['is_valid'] = false;
                    $row['errors'] = ['상단 회사가 유효하지 않습니다.'];
                }
            } else {
                $row['is_valid'] = false;
                $row['errors'] = ['업체명과 담당자명이 모두 비어 있습니다.'];
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
        $contacts = $importResults['contacts'];

        Notification::make()
            ->title('가져오기 완료')
            ->body("성공적으로 <span class='text-red-600 font-bold'>{$successCount}개의 회사와 {$contacts}개의 담당자</span>를 가져왔습니다. <span class='text-red-600 font-bold'>".
                ($errorCount > 0 ? " {$errorCount}개의 레코드</span>가 실패했습니다." : ''))
            ->success()
            ->persistent()
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
        $contacts = 0;
        $errorCount = 0;
        $errors = [];
        $currentCompany = null;

        // TODO: DB Transaction
        foreach ($validatedData as $row) {
            if ($row['is_valid']) {
                try {
                    $row['items'] = $this->doMutateBeforeCreate($row['items']);

                    if ($row['items']['name_kr'] || $row['items']['name_en']) {
                        if (! $this->checkCompanyDuplicate($row['items'])) {
                            $currentCompany = $this->createCompany($row['items']);
                            $successCount++;
                        } else {
                            $currentCompany = null;
                            $errorCount++;
                            $errors[] = "Row {$row['row']}: Company already exists";
                        }
                    } elseif ($row['items']['contact_name']) {
                        if (! $currentCompany) {
                            logger('Current company is empty, skip adding contact');

                            continue;
                        }

                        $this->createContact($currentCompany, $row['items']);
                        $contacts++;
                    }
                } catch (\Exception $e) {
                    logger($e->getMessage());
                    $errorCount++;
                    $errors[] = "Row {$row['row']}: ".$e->getMessage();
                }
            }
            // } else {
            //     $errorCount++;
            //     $errors[] = "Row {$row['row']}: ".implode(', ', $row['errors']);
            // }
        }

        logger()->info('importValidData error: '.json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'success_count' => $successCount,
            'contacts' => $contacts,
            'error_count' => $errorCount,
            'errors' => $errors,
        ];
    }

    protected function createCompany(array $data): Company
    {
        try {
            DB::beginTransaction();

            $company = new Company;
            $company->name_kr = $data['name_kr'];
            $company->name_en = $data['name_en'];
            $company->phone = $data['phone'];
            $company->email = $data['email'];
            $company->website = $data['website'];
            $company->country_code = $data['country_code'];
            $company->biz_no = $data['biz_no'];
            $company->mgmt_grade = $data['mgmt_grade'];
            $company->memo = Str::limit($data['memo']);
            $company->save();

            $this->createContact($company, $data);

            if ($data['street_line1']) {
                $company->addresses()->create([
                    'address_format' => 'full',
                    'locale' => $data['country_code'] === 'KR' ? 'ko' : 'en',
                    'postal_code' => $data['postal_code'],
                    'street_line1' => $data['street_line1'],
                    'country_code' => $data['country_code'],
                ]);
            }

            if ($data['ceo_name'] || $data['brand_name'] || $data['main_items']) {
                $company->details()->create([
                    'locale' => $data['country_code'] === 'KR' ? 'ko' : 'en',
                    'ceo_name' => $data['ceo_name'],
                    'brand_name' => $data['brand_name'],
                    'main_items' => $data['main_items'],
                ]);
            }

            if ($data['biz_type'] || $data['biz_items']) {
                $company->businessDetail()->create([
                    'biz_type' => $data['biz_type'],
                    'biz_items' => $data['biz_items'],
                ]);
            }

            if ($data['industry_code']) {
                $company->industries()->attach($data['industry_code']);
            }

            DB::commit();

            return $company;
        } catch (Exception $e) {
            logger()->error($e->getMessage());
            DB::rollback();
            throw $e;
        }
    }

    protected function createContact(Company $company, array $data): void
    {
        if ($data['contact_name']) {
            $company->contacts()->create([
                $company->country_code === 'KR' ? 'name_kr' : 'name_en' => $data['contact_name'],
                $company->country_code === 'KR' ? 'position_kr' : 'position_en' => $data['contact_position'],
                $company->country_code === 'KR' ? 'department_kr' : 'department_en' => $data['contact_department'],
                'phone' => $data['contact_phone'],
                'mobile' => $data['contact_mobile'],
                'email' => $data['contact_email'],
                'is_active' => 1,
            ]);
        }
    }

    protected function checkCompanyDuplicate(array $data): bool|string
    {
        // if ($data['name_kr']) {
        //     $normalizedName = SearchHelper::normalizeCompanySearchTerm($data['name_kr']);
        //     if (
        //         Company::where('name_kr', $data['name_kr'])
        //             ->orWhere('name_kr_normalized', $normalizedName)->exists()
        //     ) {
        //         return 'name_kr';
        //     }
        // }

        if ($url = $data['website']) {
            $url = NetHelper::extractDomain($url);

            if (Company::where('website', 'like', "%$url%")->exists()) {
                return 'website';
            }
        }

        if ($phone = $data['phone']) {
            $phone = DataNormalizer::phoneDatabaseFormat($phone, $data['country_code']);
            if (Company::where('phone', $phone)->exists()) {
                return 'phone';
            }
        }

        return false;
    }
}
