<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 space-y-6">
    <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Bokningssystem</h1>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <a href="{{ route('booking.calendar') }}" wire:navigate
           class="group rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6 hover:border-brand dark:hover:border-brand transition-colors">
            <p class="font-semibold text-zinc-900 dark:text-white group-hover:text-brand-dark dark:group-hover:text-brand">Kalender</p>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Se alla bokningar i kalendervy.</p>
        </a>
        <a href="{{ route('booking.create') }}" wire:navigate
           class="group rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6 hover:border-brand dark:hover:border-brand transition-colors">
            <p class="font-semibold text-zinc-900 dark:text-white group-hover:text-brand-dark dark:group-hover:text-brand">Boka rum</p>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Skapa en ny bokning.</p>
        </a>
        <a href="{{ route('booking.my') }}" wire:navigate
           class="group rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6 hover:border-brand dark:hover:border-brand transition-colors">
            <p class="font-semibold text-zinc-900 dark:text-white group-hover:text-brand-dark dark:group-hover:text-brand">Mina bokningar</p>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Hantera dina bokningar.</p>
        </a>
        <a href="{{ route('booking.venue-application.create') }}" wire:navigate
           class="group rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6 hover:border-brand dark:hover:border-brand transition-colors">
            <p class="font-semibold text-zinc-900 dark:text-white group-hover:text-brand-dark dark:group-hover:text-brand">Ansök om lokal</p>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Skicka en ansökan om att låna lokalen.</p>
        </a>
        <a href="{{ route('booking.resources.index') }}" wire:navigate
           class="group rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6 hover:border-brand dark:hover:border-brand transition-colors">
            <p class="font-semibold text-zinc-900 dark:text-white group-hover:text-brand-dark dark:group-hover:text-brand">Rum &amp; lokaler</p>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Se tillgängliga rum och deras detaljer.</p>
        </a>
    </div>
</div>
