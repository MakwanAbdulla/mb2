<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use ZipArchive;

class SyncIcons extends Command
{
    protected $signature = 'icons:sync {--force : Hämta även om versionen redan är installerad}';

    protected $description = 'Synka Bootstrap Icons från GitHub till public/images/icons';

    public function handle(): int
    {
        return $this->syncBootstrapIcons();
    }

    protected function syncBootstrapIcons(): int
    {
        $versionFile = storage_path('app/bootstrap-icons.version');
        $targetDir   = public_path('images/icons/bootstrap');

        $this->info('Kontrollerar senaste Bootstrap Icons-version på GitHub...');
        $release = Http::withHeaders(['User-Agent' => 'membrum-app'])
            ->timeout(15)
            ->get('https://api.github.com/repos/twbs/icons/releases/latest');

        if (! $release->successful()) {
            $this->error("Kunde inte nå GitHub API: HTTP {$release->status()}");
            return 1;
        }

        $version = ltrim($release->json('tag_name'), 'v');

        if (! $this->option('force')
            && is_file($versionFile)
            && trim(file_get_contents($versionFile)) === $version
            && is_dir($targetDir)
            && count(glob("{$targetDir}/*.svg")) > 0
        ) {
            $this->info("Bootstrap Icons {$version} redan installerade — hoppar över. (--force tvingar)");
            return 0;
        }

        $this->info("Synkar Bootstrap Icons {$version}...");

        $zipUrl  = "https://github.com/twbs/icons/releases/download/v{$version}/bootstrap-icons-{$version}.zip";
        $tempZip = storage_path('app/bootstrap-icons.zip');
        $tempExtract = storage_path('app/bootstrap-icons-temp');

        try {
            // Ladda ner ZIP
            $this->info('Laddar ner från GitHub...');
            $response = Http::timeout(120)->get($zipUrl);

            if (! $response->successful()) {
                $this->error("Kunde inte ladda ner: HTTP {$response->status()}");

                return 1;
            }

            File::put($tempZip, $response->body());
            $this->info('✓ Nedladdad');

            // Extrahera ZIP
            $this->info('Extraherar...');
            $zip = new ZipArchive;
            if ($zip->open($tempZip) !== true) {
                $this->error('Kunde inte öppna ZIP-filen');
                @unlink($tempZip);

                return 1;
            }

            File::ensureDirectoryExists($tempExtract);
            $zip->extractTo($tempExtract);
            $zip->close();
            $this->info('✓ Extraherad');

            // Kopiera SVG-filer
            $this->info('Kopierar SVG-filer...');
            File::ensureDirectoryExists($targetDir);

            // Hitta icons-mappen (kan vara i olika nivåer)
            $possiblePaths = [
                "{$tempExtract}/bootstrap-icons-{$version}/icons",
                "{$tempExtract}/bootstrap-icons-{$version}", // SVG-filerna ligger direkt här
                "{$tempExtract}/icons",
                "{$tempExtract}/bootstrap-icons/icons",
            ];

            $svgSource = null;
            foreach ($possiblePaths as $path) {
                if (is_dir($path)) {
                    // Kontrollera om det finns SVG-filer här
                    $svgFiles = File::glob("{$path}/*.svg");
                    if (! empty($svgFiles)) {
                        $svgSource = $path;
                        break;
                    }
                }
            }

            if (! $svgSource) {
                // Lista vad som finns i tempExtract rekursivt
                $this->error("Kunde inte hitta SVG-filer. Innehåll i {$tempExtract}:");

                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($tempExtract, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST
                );

                $count = 0;
                foreach ($iterator as $file) {
                    if ($count++ > 20) {
                        $this->line('  ... (fler filer)');
                        break;
                    }
                    $relativePath = str_replace($tempExtract.'/', '', $file->getPathname());
                    $this->line('  '.($file->isDir() ? '[DIR]' : '[FILE]')." {$relativePath}");
                }

                File::deleteDirectory($tempExtract);
                @unlink($tempZip);

                return 1;
            }

            $files = File::files($svgSource);
            $bar = $this->output->createProgressBar(count($files));
            $bar->start();

            $copied = 0;
            foreach ($files as $file) {
                if ($file->getExtension() === 'svg') {
                    File::copy(
                        $file->getPathname(),
                        $targetDir.'/'.$file->getFilename()
                    );
                    $copied++;
                }
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);

            // Städa upp
            File::deleteDirectory($tempExtract);
            @unlink($tempZip);

            file_put_contents($versionFile, $version);
            $this->info("✓ Klart! {$copied} ikoner kopierade till {$targetDir}");

            return 0;

        } catch (\Exception $e) {
            $this->error("Fel: {$e->getMessage()}");
            File::deleteDirectory($tempExtract);
            @unlink($tempZip);

            return 1;
        }
    }
}
