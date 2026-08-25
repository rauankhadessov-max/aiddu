@props(['type' => 'success', 'message' => null])

@php
    $class = match ($type) {
        'error' => 'ui-flash-error',
        'warning' => 'ui-flash-warning',
        default => 'ui-flash-success',
    };
@endphp

@if (filled($message) || $slot->isNotEmpty())
    <div role="status" {{ $attributes->class([$class]) }}>
        {{ $message ?? $slot }}
    </div>
@endif
