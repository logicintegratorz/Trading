<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('layout', function ($view): void {
            $pendingStockSaudas = DB::table('saudas as s')
                ->join('parties as pa', 's.party_id', '=', 'pa.id')
                ->join('products as p', 's.product_id', '=', 'p.id')
                ->whereIn('s.status', ['Open', 'Partial'])
                ->select('s.id', 's.type', 's.quantity', 's.fulfilled_quantity', 'pa.name as party_name', 'p.group_name')
                ->orderByDesc('s.date')->orderByDesc('s.id')
                ->get()
                ->groupBy('type');

            $view->with('pendingStockSaudas', $pendingStockSaudas);
        });
    }
}
