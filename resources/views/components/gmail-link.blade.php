@props(['email', 'size' => 'sm'])
@php
    $url = 'https://accounts.google.com/AccountChooser?Email=' . urlencode($email)
         . '&continue=' . urlencode('https://mail.google.com');
@endphp
<a href="{{ $url }}" target="_blank" rel="noopener"
   title="Öppna Gmail för {{ $email }}"
   class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-2.5 py-1 text-xs font-medium text-zinc-600 shadow-sm transition-colors hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-600">
    <x-bootstrap-icon name="envelope" class="{{ $size === 'xs' ? 'size-3' : 'size-3.5' }} text-red-500" />
    Gmail
</a>
