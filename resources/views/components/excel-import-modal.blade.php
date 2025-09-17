@php
    $livewire = $this;
@endphp

<div class="space-y-6">
    <!-- Step Indicator -->
    <div class="flex items-center justify-center space-x-4 mb-6">
        <div class="flex items-center">
            <div class="flex items-center justify-center w-8 h-8 rounded-full {{ $livewire->currentStep >= 1 ? 'bg-primary-600 text-white' : 'bg-gray-200 text-gray-500' }}">
                1
            </div>
            <span class="ml-2 text-sm {{ $livewire->currentStep >= 1 ? 'text-primary-600 font-medium' : 'text-gray-500' }}">
                파일 업로드
            </span>
        </div>

        <div class="w-16 h-px {{ $livewire->currentStep > 1 ? 'bg-primary-600' : 'bg-gray-200' }}"></div>

        <div class="flex items-center">
            <div class="flex items-center justify-center w-8 h-8 rounded-full {{ $livewire->currentStep >= 2 ? 'bg-primary-600 text-white' : 'bg-gray-200 text-gray-500' }}">
                2
            </div>
            <span class="ml-2 text-sm {{ $livewire->currentStep >= 2 ? 'text-primary-600 font-medium' : 'text-gray-500' }}">
                데이터 미리보기
            </span>
        </div>

        <div class="w-16 h-px {{ $livewire->currentStep > 2 ? 'bg-primary-600' : 'bg-gray-200' }}"></div>

        <div class="flex items-center">
            <div class="flex items-center justify-center w-8 h-8 rounded-full {{ $livewire->currentStep >= 3 ? 'bg-primary-600 text-white' : 'bg-gray-200 text-gray-500' }}">
                3
            </div>
            <span class="ml-2 text-sm {{ $livewire->currentStep >= 3 ? 'text-primary-600 font-medium' : 'text-gray-500' }}">
                검증 결과 확인
            </span>
        </div>
    </div>

    <!-- Step 1: File Upload -->
    @if ($livewire->currentStep == 1)
        <div class="text-center">
            <div class="mb-4">
                <svg class="w-10 h-10 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                </svg>
            </div>
            <h3 class="text-lg font-medium text-gray-900 mb-2">Excel 파일 업로드</h3>
            <p class="text-sm text-gray-500 mb-4">
                회사 정보가 포함된 Excel 파일을 선택해주세요.
            </p>

            <!-- File upload component will be rendered by Filament form -->
        </div>
    @endif

    <!-- Step 2: Data Preview -->
    @if ($livewire->currentStep == 2)
        <div>
            <h3 class="text-lg font-medium text-gray-900 mb-4">데이터 미리보기</h3>

            @if (count($livewire->validationResults) > 0)
                <div class="mb-4">
                    <p class="text-sm text-gray-600">
                        총 {{ count($livewire->validationResults) }}개의 행이 발견되었습니다.
                        '검증' 버튼을 클릭하여 데이터를 확인하세요.
                    </p>
                </div>

                <div class="max-h-96 overflow-y-auto border border-gray-200 rounded-lg">
                    <table class="w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">행</th>

                                @foreach ($livewire->importColumns as $key => $column)
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ $column['label'] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach ($livewire->validationResults as $row)
                                <tr>
                                    <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900">{{ $row['row'] }}</td>
                                    @foreach ($livewire->importColumns as $key => $column)
                                    <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900">{{ $row['items'][$column['index']] }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="text-center py-8">
                    <p class="text-gray-500">데이터를 불러오고 있습니다...</p>
                </div>
            @endif
        </div>
    @endif

    <!-- Step 3: Validation Results -->
    @if ($livewire->currentStep == 3)
        <div>
            <h3 class="text-lg font-medium text-gray-900 mb-4">검증 결과</h3>

            @php
                $summary = app(\App\Services\ExcelImportService::class)->getValidationSummary($livewire->validationResults);
            @endphp

            <!-- Summary -->
            <div class="grid grid-cols-3 gap-4 mb-6">
                <div class="bg-blue-50 p-4 rounded-lg text-center">
                    <div class="text-2xl font-bold text-blue-600">{{ $summary['total'] }}</div>
                    <div class="text-sm text-blue-600">전체</div>
                </div>
                <div class="bg-green-50 p-4 rounded-lg text-center">
                    <div class="text-2xl font-bold text-green-600">{{ $summary['valid'] }}</div>
                    <div class="text-sm text-green-600">성공</div>
                </div>
                <div class="bg-red-50 p-4 rounded-lg text-center">
                    <div class="text-2xl font-bold text-red-600">{{ $summary['invalid'] }}</div>
                    <div class="text-sm text-red-600">실패</div>
                </div>
            </div>

            <!-- Detailed Results -->
            <div class="max-h-96 overflow-y-auto border border-gray-200 rounded-lg">
                <table class="w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">상태</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">행</th>
                            @foreach ($livewire->headers as $header)
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ $header }}</th>
                            @endforeach
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">오류</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach ($livewire->validationResults as $row)
                            <tr class="{{ $row['is_valid'] ? 'bg-green-50' : 'bg-red-50' }}">
                                <td class="px-4 py-2 whitespace-nowrap">
                                    @if ($row['is_valid'])
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            성공
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                            실패
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900">{{ $row['row'] }}</td>
                                @foreach ($row['items'] as $value)
                                    <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900">{{ $value }}</td>
                                @endforeach
                                <td class="px-4 py-2 text-sm text-red-600">
                                    @if (!empty($row['errors']))
                                        {{ implode(', ', $row['errors']) }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($summary['valid'] > 0)
                <div class="mt-4 p-4 bg-yellow-50 rounded-lg">
                    <p class="text-sm text-yellow-800">
                        <strong>{{ $summary['valid'] }}개</strong>의 회사가 성공적으로 가져와집니다.
                        계속 진행하시겠습니까?
                    </p>
                </div>
            @else
                <div class="mt-4 p-4 bg-red-50 rounded-lg">
                    <p class="text-sm text-red-800">
                        가져올 수 있는 유효한 데이터가 없습니다. 파일을 확인하고 다시 시도해주세요.
                    </p>
                </div>
            @endif
        </div>
    @endif
</div>
