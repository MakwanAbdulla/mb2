@props(['name', 'class' => 'size-5'])

@if($name)
    @php
        $path = public_path("images/icons/bootstrap/{$name}.svg");
    @endphp
    @if(file_exists($path))
        @php
            // Every Bootstrap Icons SVG ships with its own class="bi bi-xxx"
            // attribute — strip it before merging ours, otherwise the <svg>
            // ends up with two class attributes (invalid HTML).
            $svg = preg_replace('/\sclass="[^"]*"/', '', file_get_contents($path), 1);
        @endphp
        {!! str_replace('<svg ', '<svg ' . $attributes->merge(['class' => $class])->toHtml() . ' ', $svg) !!}
    @endif
@endif
