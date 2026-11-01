<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MasterDataController extends Controller
{
    /** @return array{table: string, label: string, plural: string, max: int} */
    private function configuration(string $master): array
    {
        abort_unless(in_array($master, ['group-heads', 'parties'], true), 404);

        return $master === 'group-heads'
            ? ['table' => 'group_heads', 'label' => 'Group Head', 'plural' => 'Group Heads', 'max' => 80]
            : ['table' => 'parties', 'label' => 'Party', 'plural' => 'Parties', 'max' => 120];
    }

    public function index(string $master): View
    {
        $configuration = $this->configuration($master);
        $values = DB::table($configuration['table'])->orderBy('name')->get();

        return view('masters.index', compact('master', 'configuration', 'values'));
    }

    public function store(Request $request, string $master): RedirectResponse
    {
        $configuration = $this->configuration($master);
        $data = $request->validate(['name' => ['required', 'string', 'max:'.$configuration['max'], Rule::unique($configuration['table'], 'name')]]);
        $name = trim($data['name']);
        $this->ensureUniqueName($configuration['table'], $name);
        DB::table($configuration['table'])->insert(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', $configuration['label'].' added.');
    }

    public function update(Request $request, string $master, int $id): RedirectResponse
    {
        $configuration = $this->configuration($master);
        abort_unless(DB::table($configuration['table'])->where('id', $id)->exists(), 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:'.$configuration['max'], Rule::unique($configuration['table'], 'name')->ignore($id)]]);
        $this->ensureUniqueName($configuration['table'], trim($data['name']), $id);
        $oldName = DB::table($configuration['table'])->where('id', $id)->value('name');
        DB::transaction(function () use ($configuration, $master, $id, $data, $oldName): void {
            $newName = trim($data['name']);
            DB::table($configuration['table'])->where('id', $id)->update(['name' => $newName, 'updated_at' => now()]);
            if ($master === 'group-heads') {
                DB::table('products')->where('group_name', $oldName)->update(['group_name' => $newName, 'updated_at' => now()]);
            } else {
                DB::table('records')->where('party_id', $id)->update(['party' => $newName, 'updated_at' => now()]);
            }
        });

        return back()->with('success', $configuration['label'].' updated.');
    }

    public function destroy(string $master, int $id): RedirectResponse
    {
        $configuration = $this->configuration($master);
        $foreignKey = $master === 'group-heads' ? 'group_head_id' : 'party_id';
        $isUsed = DB::table('records')->where($foreignKey, $id)->exists();
        if ($master === 'group-heads') {
            $name = DB::table('group_heads')->where('id', $id)->value('name');
            $isUsed = $isUsed || ($name && DB::table('products')->where('group_name', $name)->exists());
        } else {
            $isUsed = $isUsed || DB::table('saudas')->where('party_id', $id)->exists();
        }
        if ($isUsed) {
            return back()->withErrors(['name' => $configuration['label'].' is already used by a product, Sauda, order, or transaction and cannot be deleted.']);
        }
        DB::table($configuration['table'])->where('id', $id)->delete();

        return back()->with('success', $configuration['label'].' deleted.');
    }

    private function ensureUniqueName(string $table, string $name, ?int $ignoreId = null): void
    {
        $query = DB::table($table)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)]);
        if ($ignoreId) $query->where('id', '!=', $ignoreId);
        if ($query->exists()) throw ValidationException::withMessages(['name' => 'This value already exists.']);
    }
}
