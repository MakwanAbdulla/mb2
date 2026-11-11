<?php

namespace App\Livewire\Admin\Pages;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class ImageBrowser extends Component
{
    use WithFileUploads;

    public string $search = '';

    public ?TemporaryUploadedFile $uploadFile = null;

    public array $images = [];

    public bool $showUpload = false;

    public string $uploadError = '';

    public function mount(): void
    {
        $this->loadImages();
    }

    public function updatedSearch(): void
    {
        $this->loadImages();
    }

    public function loadImages(): void
    {
        $this->images = [];

        // Images uploaded to storage/app/public/pages
        $storageFiles = Storage::disk('public')->files('pages');
        foreach ($storageFiles as $file) {
            if (! $this->isValidImage($file)) {
                continue;
            }
            $this->images[] = [
                'path' => $file,
                'url' => asset('storage/'.$file),
                'name' => basename($file),
                'source' => 'storage',
            ];
        }

        // Static images from public/images (excluding icons/)
        $publicImagesPath = public_path('images');
        if (is_dir($publicImagesPath)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($publicImagesPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($files as $fileInfo) {
                if (! $fileInfo->isFile()) {
                    continue;
                }
                $relativePath = str_replace($publicImagesPath.'/', '', $fileInfo->getPathname());
                if (str_starts_with($relativePath, 'icons/')) {
                    continue;
                }
                if (! $this->isValidImage($relativePath)) {
                    continue;
                }
                $urlPath = implode('/', array_map('rawurlencode', explode('/', $relativePath)));
                $this->images[] = [
                    'path' => 'images/'.$relativePath,
                    'url' => asset('images/'.$urlPath),
                    'name' => $fileInfo->getFilename(),
                    'source' => 'public',
                ];
            }
        }
    }

    private function isValidImage(string $file): bool
    {
        if (! empty($this->search) && ! str_contains(strtolower($file), strtolower($this->search))) {
            return false;
        }

        return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    }

    public function selectImage(string $path): void
    {
        $url = str_starts_with($path, 'pages/')
            ? asset('storage/'.$path)
            : asset($path);

        $this->dispatch('imageSelected', $url, $path);
    }

    public function uploadImage(): void
    {
        $this->validate([
            'uploadFile' => 'required|image|max:5120',
        ], [
            'uploadFile.required' => 'Välj en bildfil.',
            'uploadFile.image' => 'Filen måste vara en bild.',
            'uploadFile.max' => 'Bilden får max vara 5 MB.',
        ]);

        try {
            $originalName = pathinfo($this->uploadFile->getClientOriginalName(), PATHINFO_FILENAME);
            $extension = $this->uploadFile->getClientOriginalExtension();
            $cleanName = trim(preg_replace('/-+/', '-', preg_replace('/[^a-z0-9\-_]/i', '-', $originalName)), '-');
            $filename = $cleanName.'-'.time().'.'.$extension;

            $counter = 1;
            while (Storage::disk('public')->exists('pages/'.$filename)) {
                $filename = $cleanName.'-'.time().'-'.$counter.'.'.$extension;
                $counter++;
            }

            $path = $this->uploadFile->storeAs('pages', $filename, 'public');
            AuditLog::record('page_image_uploaded', 'cms', $filename, null);
            $this->uploadError = '';
            $this->uploadFile = null;
            $this->showUpload = false;
            $this->loadImages();
            $this->selectImage($path);
        } catch (\Exception $e) {
            $this->uploadError = 'Fel vid uppladdning: '.$e->getMessage();
        }
    }

    public function deleteImage(string $path): void
    {
        if (str_starts_with($path, 'pages/')) {
            Storage::disk('public')->delete($path);
            AuditLog::record('page_image_deleted', 'cms', $path, null);
            $this->loadImages();
        }
    }

    public function render()
    {
        return view('livewire.admin.pages.image-browser');
    }
}
