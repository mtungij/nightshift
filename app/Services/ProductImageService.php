<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class ProductImageService
{
    private const CATEGORY_IMAGES = [
        'beer' => 'beer',
        'wine' => 'wine',
        'whisky' => 'whisky',
        'whiskey' => 'whisky',
        'vodka' => 'vodka',
        'gin' => 'gin',
        'spirits' => 'spirits',
        'rum' => 'spirits',
        'champagne' => 'champagne',
        'energy drinks' => 'energy-drinks',
        'soft drinks' => 'soft-drinks',
        'water' => 'water',
        'cocktails' => 'cocktails',
        'food' => 'food',
        'juice' => 'juice',
        'milkshakes' => 'milkshakes',
        'coffee' => 'coffee',
    ];

    /** @return array{image: ?string, category_image: ?string} */
    public function presentation(Product $product): array
    {
        $path = $product->image_path;
        $category = self::CATEGORY_IMAGES[mb_strtolower(trim($product->category->name))] ?? null;

        return [
            'image' => $this->ownsPath($product->company_id, $path)
                ? route('nightshift.product-image', ['id' => $product->id, 'v' => $this->version($path)], false)
                : null,
            'category_image' => $category ? "/images/product-categories/{$category}.webp" : null,
        ];
    }

    public function ownsPath(int $companyId, ?string $path): bool
    {
        return $path !== null
            && preg_match("~\Aproduct-images/{$companyId}/[a-zA-Z0-9_-]+\.(?:jpe?g|png|webp)\z~", $path) === 1;
    }

    public function version(string $path): string
    {
        return hash('sha256', $path);
    }

    public function store(int $companyId, UploadedFile $file): string
    {
        $directory = "product-images/{$companyId}";
        $name = (string) Str::uuid();
        $disk = Storage::disk('local');

        if (! function_exists('imagewebp') || ! (imagetypes() & IMG_WEBP)) {
            $path = $file->storeAs($directory, $name.'.'.$file->extension(), 'local');
            if ($path === false) {
                throw new RuntimeException('Product image could not be stored.');
            }

            return $path;
        }

        $source = @imagecreatefromstring($file->getContent());
        if (! $source) {
            throw ValidationException::withMessages(['image' => __('ui.Choose a valid product image.')]);
        }
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, 640 / max($width, $height));
        $width = max(1, (int) round($width * $scale));
        $height = max(1, (int) round($height * $scale));
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        $stream = tmpfile();
        if (! $stream) {
            throw new RuntimeException('Product image could not be staged.');
        }
        $path = "{$directory}/{$name}.webp";
        try {
            if (! imagewebp($image, $stream, 82) || ftell($stream) === 0 || ! rewind($stream) || ! $disk->put($path, $stream)) {
                throw new RuntimeException('Product image could not be stored.');
            }
        } catch (Throwable $error) {
            $this->delete($companyId, $path);
            throw $error;
        } finally {
            fclose($stream);
        }

        return $path;
    }

    public function delete(int $companyId, ?string $path): void
    {
        if (! $this->ownsPath($companyId, $path)) {
            return;
        }
        try {
            Storage::disk('local')->delete($path);
        } catch (Throwable $error) {
            report($error);
        }
    }
}
