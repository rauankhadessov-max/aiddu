<button {{ $attributes->merge(['type' => 'submit', 'class' => 'ui-btn-primary']) }}>
    {{ $slot }}
</button>
