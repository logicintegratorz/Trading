<div class="order-line" data-order-row>
    <span class="line-number">{{ is_numeric($index) ? $index + 1 : '' }}</span>
    <label class="item-product"><span>Product</span><select name="items[{{ $index }}][product_id]" data-product-select><option value="">Manual description</option>@foreach($products as $product)<option value="{{ $product->id }}" data-name="{{ $product->name }}" data-weight="{{ $product->weight }}" data-base-rate="{{ number_format($product->purchase_rate_cents / 100, 2, '.', '') }}" data-rate="{{ number_format(($module === 'purchase-orders' ? $product->purchase_rate_cents : $product->sale_rate_cents) / 100, 2, '.', '') }}" @selected((string)($item['product_id'] ?? '') === (string)$product->id)>{{ $product->name }}</option>@endforeach</select></label>
    <label class="item-description"><span>Description *</span><input required maxlength="160" name="items[{{ $index }}][description]" value="{{ $item['description'] ?? '' }}" data-item-description></label>
    <label><span>KG</span><input type="number" min="0" step="0.001" name="items[{{ $index }}][weight_kg]" value="{{ $item['weight_kg'] ?? '' }}" data-item-weight></label>
    <label><span>Difference</span><input type="number" min="0" step="0.01" name="items[{{ $index }}][difference]" value="{{ $item['difference'] ?? '' }}"></label>
    <label><span>Base Rate</span><input type="number" min="0" step="0.01" name="items[{{ $index }}][base_rate]" value="{{ $item['base_rate'] ?? '' }}" data-item-base-rate></label>
    <label><span>QTY (Ton) *</span><input required type="number" min="0.001" step="0.001" name="items[{{ $index }}][quantity]" value="{{ $item['quantity'] ?? '' }}" data-item-quantity></label>
    <label><span>Pcs</span><input type="number" min="0" step="1" name="items[{{ $index }}][pieces]" value="{{ $item['pieces'] ?? '' }}" data-item-pieces></label>
    <label><span>Bundles</span><input type="number" min="0" step="1" name="items[{{ $index }}][bundles]" value="{{ $item['bundles'] ?? '' }}" data-item-bundles></label>
    <label><span>Rate *</span><input required type="number" min="0.01" step="0.01" name="items[{{ $index }}][rate]" value="{{ $item['rate'] ?? '' }}" data-item-rate></label>
    <div class="line-amount"><span>Amount</span><strong data-item-amount>₹0.00</strong></div>
    <button class="remove-line" type="button" data-remove-order-item title="Remove line">×</button>
</div>
