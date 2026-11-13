<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Product Added</title></head>
<body>
<p>Product added. This window will close automatically.</p>
<script>
    const product = @json([
        'id' => $product->id,
        'name' => $product->name,
        'weight' => $product->weight,
        'rate' => number_format($product->sale_rate_cents / 100, 2, '.', ''),
    ]);
    if (window.opener) {
        window.opener.postMessage({ type: 'tradeflow-product-created', product }, window.location.origin);
        window.close();
    }
</script>
</body>
</html>
