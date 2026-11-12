<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class GateController
{
    public function index(Request $request): View
    {
        $type = in_array($request->query('type'), ['sale', 'purchase'], true) ? $request->query('type') : '';
        $partyId = $request->integer('party_id');
        $date = is_string($request->query('date')) ? $request->query('date') : '';
        $search = is_string($request->query('q')) ? mb_substr(trim($request->query('q')), 0, 120) : '';
        $query = DB::table('gate_entries');
        if ($type) $query->where('type', $type);
        if ($partyId) $query->where('party_id', $partyId);
        if ($date) $query->where('entry_date', $date);
        if ($search) $query->where(fn ($builder) => $builder->where('party_name', 'like', '%'.$search.'%')->orWhere('vehicle_number', 'like', '%'.$search.'%'));
        $entries = $query->orderByDesc('entry_date')->orderByDesc('id')->paginate(25)->withQueryString();
        $parties = DB::table('parties')->orderBy('name')->get();
        $summary = DB::table('gate_entries')->selectRaw("COUNT(*) as total, SUM(CASE WHEN type = 'purchase' THEN 1 ELSE 0 END) as purchase_total, SUM(CASE WHEN type = 'sale' THEN 1 ELSE 0 END) as sale_total, COUNT(DISTINCT vehicle_number) as vehicles")->first();

        return view('gate.index', compact('entries', 'parties', 'summary', 'type', 'partyId', 'date', 'search'));
    }

    public function create(): View
    {
        $parties = DB::table('parties')->orderBy('name')->get();
        $entry = null;
        return view('gate.form', compact('parties', 'entry'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'party_id' => ['required', 'integer', 'exists:parties,id'],
            'vehicle_number' => ['required', 'string', 'max:60'],
            'type' => ['required', 'in:sale,purchase'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'photo_webp' => ['nullable', 'string', 'max:8000000'],
            'entry_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:today'],
        ]);
        $party = DB::table('parties')->where('id', $data['party_id'])->value('name');
        $photoPath = filled($data['photo_webp'] ?? null) ? $this->storeBrowserWebp($data['photo_webp']) : ($request->hasFile('photo') ? $this->storeCompressedWebp($request) : null);
        DB::table('gate_entries')->insert([
            'party_id' => $data['party_id'], 'party_name' => $party, 'vehicle_number' => mb_strtoupper(trim($data['vehicle_number'])),
            'type' => $data['type'], 'photo_path' => $photoPath, 'entry_date' => $data['entry_date'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        return redirect()->route('gate.index')->with('success', 'Gate entry saved successfully.');
    }

    public function edit(int $id): View
    {
        $entry = DB::table('gate_entries')->where('id', $id)->first();
        abort_unless($entry, 404);
        $parties = DB::table('parties')->orderBy('name')->get();
        return view('gate.form', compact('parties', 'entry'));
    }

    /** Serve the image without depending on a public /storage symlink. */
    public function photo(int $id)
    {
        $entry = DB::table('gate_entries')->where('id', $id)->first();
        abort_unless($entry?->photo_path && Storage::disk('public')->exists($entry->photo_path), 404);

        return response()->file(Storage::disk('public')->path($entry->photo_path));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $entry = DB::table('gate_entries')->where('id', $id)->first();
        abort_unless($entry, 404);
        $data = $request->validate([
            'party_id' => ['required', 'integer', 'exists:parties,id'], 'vehicle_number' => ['required', 'string', 'max:60'],
            'type' => ['required', 'in:sale,purchase'], 'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'photo_webp' => ['nullable', 'string', 'max:8000000'], 'entry_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:today'],
        ]);
        $party = DB::table('parties')->where('id', $data['party_id'])->value('name');
        $photoPath = $entry->photo_path;
        if (filled($data['photo_webp'] ?? null) || $request->hasFile('photo')) {
            if ($photoPath) Storage::disk('public')->delete($photoPath);
            $photoPath = filled($data['photo_webp'] ?? null) ? $this->storeBrowserWebp($data['photo_webp']) : $this->storeCompressedWebp($request);
        }
        DB::table('gate_entries')->where('id', $id)->update(['party_id' => $data['party_id'], 'party_name' => $party, 'vehicle_number' => mb_strtoupper(trim($data['vehicle_number'])), 'type' => $data['type'], 'photo_path' => $photoPath, 'entry_date' => $data['entry_date'], 'updated_at' => now()]);
        return redirect()->route('gate.index')->with('success', 'Gate entry updated successfully.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $entry = DB::table('gate_entries')->where('id', $id)->first();
        if ($entry?->photo_path) Storage::disk('public')->delete($entry->photo_path);
        DB::table('gate_entries')->where('id', $id)->delete();
        return redirect()->route('gate.index')->with('success', 'Gate entry deleted.');
    }

    private function storeCompressedWebp(Request $request): string
    {
        if (! function_exists('imagewebp')) {
            throw ValidationException::withMessages(['photo' => 'WebP image compression is not available on this server. Enable the PHP GD extension.']);
        }
        $file = $request->file('photo');
        $source = match ($file->getMimeType()) {
            'image/jpeg' => @imagecreatefromjpeg($file->getPathname()),
            'image/png' => @imagecreatefrompng($file->getPathname()),
            'image/webp' => @imagecreatefromwebp($file->getPathname()),
            default => false,
        };
        if (! $source) throw ValidationException::withMessages(['photo' => 'This image could not be processed. Please choose a valid JPG, PNG or WEBP photo.']);

        $width = imagesx($source); $height = imagesy($source); $maximum = 1600;
        $scale = min(1, $maximum / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale)); $targetHeight = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($target, false); imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        ob_start(); imagewebp($target, null, 78); $contents = ob_get_clean();
        imagedestroy($source); imagedestroy($target);
        $path = 'gate-entries/'.now()->format('Y/m').'/'.uniqid('gate_', true).'.webp';
        Storage::disk('public')->put($path, $contents);
        return $path;
    }

    private function storeBrowserWebp(string $encodedImage): string
    {
        if (! preg_match('/^data:image\/(webp|png|jpeg);base64,/', $encodedImage, $matches)) {
            throw ValidationException::withMessages(['photo' => 'Photo compression failed. Please select the photo again.']);
        }
        $contents = base64_decode(substr($encodedImage, strpos($encodedImage, ',') + 1), true);
        if ($contents === false || strlen($contents) === 0 || strlen($contents) > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['photo' => 'Compressed photo is invalid or exceeds 5 MB.']);
        }
        $imageInfo = @getimagesizefromstring($contents);
        if (! $imageInfo || ! in_array($imageInfo['mime'] ?? '', ['image/webp', 'image/png', 'image/jpeg'], true)) {
            throw ValidationException::withMessages(['photo' => 'The captured file is not a valid image. Please capture it again.']);
        }
        if ($matches[1] !== 'webp') {
            if (! function_exists('imagewebp')) {
                throw ValidationException::withMessages(['photo' => 'This device could not create WebP. Please choose a JPG or PNG photo and try again.']);
            }
            $source = @imagecreatefromstring($contents);
            if (! $source) throw ValidationException::withMessages(['photo' => 'This captured image could not be processed. Please capture it again.']);
            ob_start();
            imagewebp($source, null, 78);
            $contents = ob_get_clean();
            imagedestroy($source);
        }
        $path = 'gate-entries/'.now()->format('Y/m').'/'.uniqid('gate_', true).'.webp';
        Storage::disk('public')->put($path, $contents);
        return $path;
    }
}
