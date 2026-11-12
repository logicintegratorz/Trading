@php
    $selectedProduct = $products->firstWhere('id', $item['product_id'] ?? null);
    preg_match_all('/\d+(?:\.\d+)?/', (string) ($selectedProduct?->name ?? $item['description'] ?? ''), $weightMatches);
    $displayWeight = $weightMatches[0] ? end($weightMatches[0]) : ($item['weight_kg'] ?? '');
@endphp
<div class="order-line" data-order-row>
    <span class="line-number">{{ is_numeric($index) ? $index + 1 : '' }}</span>
    <label class="item-product"><span>Product</span><select name="items[{{ $index }}][product_id]" data-product-select><option value="">Manual description</option>@foreach($products as $product)<option value="{{ $product->id }}" data-name="{{ $product->name }}" data-weight="{{ $product->weight }}" data-rate="{{ number_format($product->sale_rate_cents / 100, 2, '.', '') }}" @selected((string)($item['product_id'] ?? '') === (string)$product->id)>{{ $product->name }}</option>@endforeach</select></label>
    <label class="item-description"><span>Description *</span><input required maxlength="160" name="items[{{ $index }}][description]" value="{{ $item['description'] ?? '' }}" data-item-description></label>
    <label><span>KG <small>(last product value)</small></span><input readonly tabindex="-1" name="items[{{ $index }}][weight_kg]" value="{{ $displayWeight }}" data-item-weight></label>
    <label><span>QTY (Ton) *</span><input required type="number" min="0.001" step="0.001" name="items[{{ $index }}][quantity]" value="{{ $item['quantity'] ?? '' }}" data-item-quantity></label>
    <label><span>Pcs <small>(auto)</small></span><input readonly tabindex="-1" type="number" min="0" step="1" name="items[{{ $index }}][pieces]" value="{{ $item['pieces'] ?? '' }}" data-item-pieces></label>
    <label><span>Bundles <small>(auto ÷ 52)</small></span><input readonly tabindex="-1" type="number" min="0" step="1" name="items[{{ $index }}][bundles]" value="{{ $item['bundles'] ?? '' }}" data-item-bundles></label>
    <button class="remove-line" type="button" data-remove-order-item title="Remove line">×</button>
</div>
