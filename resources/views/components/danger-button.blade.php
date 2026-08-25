<button {{ $attributes->merge(['type' => 'submit', 'class' => 'ui-btn-danger']) }}>
    {{ $slot }}
</button>
