<?php

namespace Konnco\FilamentImport;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Konnco\FilamentImport\Actions\ImportField;
use Konnco\FilamentImport\Concerns\HasActionMutation;
use Konnco\FilamentImport\Concerns\HasActionUniqueField;
use Konnco\FilamentImport\Concerns\HasTemporaryDisk;
use Konnco\FilamentImport\Concerns\HasValidation;
use Maatwebsite\Excel\Concerns\Importable;

class Import
{
    use HasActionMutation;
    use HasActionUniqueField;
    use HasTemporaryDisk;
    use HasValidation;
    use Importable;

    protected string $spreadsheet;

    protected Collection $fields;

    protected array $formSchemas;

    protected string|Model $model;

    protected string $disk = 'local';

    protected bool $shouldSkipHeader = false;

    protected bool $shouldMassCreate = true;

    protected bool $shouldHandleBlankRows = false;

    protected ?Closure $handleRecordCreation = null;

    public static function make(string $spreadsheetFilePath): self
    {
        return (new self)
            ->spreadsheet($spreadsheetFilePath);
    }

    public function fields(Collection $fields): static
    {
        $this->fields = $fields;

        return $this;
    }

    public function formSchemas(array $formSchemas): static
    {
        $this->formSchemas = $formSchemas;

        return $this;
    }

    public function spreadsheet($spreadsheet): static
    {
        $this->spreadsheet = $spreadsheet;

        return $this;
    }

    public function model(string $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function disk($disk = 'local'): static
    {
        $this->disk = $disk;

        return $this;
    }

    public function skipHeader(bool $shouldSkipHeader): static
    {
        $this->shouldSkipHeader = $shouldSkipHeader;

        return $this;
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

    public function getSpreadsheetData(): Collection
    {
        $data = $this->toCollection($this->temporaryDiskIsRemote() ? $this->spreadsheet : new UploadedFile(Storage::disk($this->disk)->path($this->spreadsheet), $this->spreadsheet))
            ->first()
            ->skip((int) $this->shouldSkipHeader);
        if (! $this->shouldHandleBlankRows) {
            return $data;
        }

        return $data->filter(function ($row) {
            return $row->filter()->isNotEmpty();
        });
    }

    public function validated($data, $rules, $customMessages, $line, string &$errorMessage, bool $notification = true)
    {
        $validator = Validator::make($data, $rules, $customMessages);

        try {
            if ($validator->fails()) {
                if ($notification) {
                    Notification::make()
                        ->danger()
                        ->title(trans('filament-import::actions.import_failed_title'))
                        ->body(trans('filament-import::validators.message', ['line' => $line, 'error' => $validator->errors()->first()]))
                        ->persistent()
                        ->send();
                }

                $errorMessage = trans('filament-import::validators.message', ['line' => $line, 'error' => $validator->errors()->first()]);

                return false;
            }
        } catch (\Exception $e) {
            return $data;
        }

        return $data;
    }

    public function handleRecordCreation(?Closure $closure): static
    {
        $this->handleRecordCreation = $closure;

        return $this;
    }

    public function validate(): static
    {
        $imported = [];
        $failed = [];

        foreach ($this->getSpreadsheetData() as $line => $row) {
            $prepareInsert = collect([]);
            $rules = [];
            $validationMessages = [];

            foreach (Arr::dot($this->fields) as $key => $value) {
                $field = $this->formSchemas[$key];
                $fieldValue = $value;

                if ($field instanceof ImportField) {
                    // check if field is optional
                    if (! $field->isRequired() && blank(@$row[$value])) {
                        continue;
                    }

                    $fieldValue = $field->doMutateBeforeCreate($row[$value], collect($row)) ?? $row[$value];
                    $rules[$key] = $field->getValidationRules();
                    if (count($field->getCustomValidationMessages())) {
                        $validationMessages[$key] = $field->getCustomValidationMessages();
                    }
                }

                $prepareInsert[$key] = $fieldValue;
            }

            $errorMessage = '';
            $prepareInsert = $this->validated(
                data: Arr::undot($prepareInsert),
                rules: $rules,
                customMessages: $validationMessages,
                line: $line + 1,
                errorMessage: $errorMessage,
                notification: false
            );

            if (! $prepareInsert) {
                $failed[] = [
                    'line' => $line + 1,
                    'data' => $row->toArray(),
                    'error' => $errorMessage,
                ];

                continue;
            }

            $prepareInsert = $this->doMutateBeforeCreate($prepareInsert);

            if ($this->uniqueField !== false) {
                if (is_null($prepareInsert[$this->uniqueField] ?? null)) {
                    $failed[] = [
                        'line' => $line + 1,
                        'data' => $row->toArray(),
                        'error' => "{$this->uniqueField} is empty",
                    ];

                    continue;
                }

                $exists = (new $this->model)->where($this->uniqueField, $prepareInsert[$this->uniqueField] ?? null)->first();
                if ($exists /* The `instanceof` keyword in PHP is used to determine if an object is
                an instance of a specific class or implements a specific interface.
                It returns `true` if the object is an instance of the specified
                class or implements the specified interface, and `false` otherwise.
                It is commonly used in conditional statements to check the type of
                an object before performing certain operations on it. */
                instanceof $this->model) {
                    $failed[] = [
                        'line' => $line + 1,
                        'data' => $row->toArray(),
                        'error' => "{$this->uniqueField} is not unique",
                    ];

                    continue;
                }
            }

            $imported[] = [
                'line' => $line + 1,
                'data' => $row->toArray(),
            ];
        }

        $result = [
            'imported' => $imported,
            'failed' => $failed,
        ];

        $this->doActionAfterValidation($result);

        return $this;
    }

    public function execute()
    {
        $importSuccess = true;
        $skipped = 0;
        DB::transaction(function () use (&$importSuccess, &$skipped) {
            foreach ($this->getSpreadsheetData() as $line => $row) {
                $prepareInsert = collect([]);
                $rules = [];
                $validationMessages = [];

                foreach (Arr::dot($this->fields) as $key => $value) {
                    $field = $this->formSchemas[$key];
                    $fieldValue = $value;

                    if ($field instanceof ImportField) {
                        // check if field is optional
                        if (! $field->isRequired() && blank(@$row[$value])) {
                            continue;
                        }

                        $fieldValue = $field->doMutateBeforeCreate($row[$value], collect($row)) ?? $row[$value];
                        $rules[$key] = $field->getValidationRules();
                        if (count($field->getCustomValidationMessages())) {
                            $validationMessages[$key] = $field->getCustomValidationMessages();
                        }
                    }

                    $prepareInsert[$key] = $fieldValue;
                }

                $errorMessage = '';
                $prepareInsert = $this->validated(
                    data: Arr::undot($prepareInsert),
                    rules: $rules,
                    customMessages: $validationMessages,
                    line: $line + 1,
                    errorMessage: $errorMessage
                );

                if (! $prepareInsert) {
                    DB::rollBack();
                    $importSuccess = false;

                    break;
                }

                $prepareInsert = $this->doMutateBeforeCreate($prepareInsert);

                if ($this->uniqueField !== false) {
                    if (is_null($prepareInsert[$this->uniqueField] ?? null)) {
                        DB::rollBack();
                        $importSuccess = false;

                        break;
                    }

                    $exists = (new $this->model)->where($this->uniqueField, $prepareInsert[$this->uniqueField] ?? null)->first();
                    if ($exists /* The `instanceof` keyword in PHP is used to determine if an object is
                    an instance of a specific class or implements a specific interface.
                    It returns `true` if the object is an instance of the specified
                    class or implements the specified interface, and `false` otherwise.
                    It is commonly used in conditional statements to check the type of
                    an object before performing certain operations on it. */
                    instanceof $this->model) {
                        $skipped++;

                        continue;
                    }
                }

                if (! $this->handleRecordCreation) {
                    if (! $this->shouldMassCreate) {
                        $model = (new $this->model)->fill($prepareInsert);
                        $model = tap($model, function ($instance) {
                            $instance->save();
                        });
                    } else {
                        $model = $this->model::create($prepareInsert);
                    }
                } else {
                    $closure = $this->handleRecordCreation;
                    $model = $closure($prepareInsert);
                }

                $this->doMutateAfterCreate($model, $prepareInsert);
            }
        });

        if ($importSuccess) {
            Notification::make()
                ->success()
                ->title(trans('filament-import::actions.import_succeeded_title'))
                ->body(trans('filament-import::actions.import_succeeded', ['count' => count($this->getSpreadsheetData()), 'skipped' => $skipped]))
                ->persistent()
                ->send();
        }

        if (! $importSuccess) {
            Notification::make()
                ->danger()
                ->title(trans('filament-import::actions.import_failed_title'))
                ->body(trans('filament-import::actions.import_failed'))
                ->persistent()
                ->send();
        }
    }
}
