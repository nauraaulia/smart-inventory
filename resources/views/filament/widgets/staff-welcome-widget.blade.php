@php
    $user = filament()->auth()->user();
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex w-full items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <x-filament-panels::avatar.user
                    size="lg"
                    :user="$user"
                    loading="lazy"
                />

                <div>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">
                        Welcome
                    </h2>
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        {{ filament()->getUserName($user) }}
                    </p>
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">
                {{ now()->translatedFormat('l, d M Y') }}
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
