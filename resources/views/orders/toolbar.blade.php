<nav class="voucher-actions" aria-label="Voucher actions">
    <a class="voucher-action" href="{{ route('orders.create', $module) }}"><b>＋</b><span>Add</span></a>
    @if($order)<a class="voucher-action" href="{{ route('orders.edit', [$module, $order->id]) }}"><b>✎</b><span>Edit</span></a>@else<button class="voucher-action" disabled type="button"><b>✎</b><span>Edit</span></button>@endif
    @if(($mode ?? '') === 'view' && $previousOrder)<a class="voucher-action" href="{{ route('orders.show', [$module, $previousOrder->id]) }}" title="Open previous voucher"><b>‹</b><span>Previous</span></a>@else<button class="voucher-action" disabled type="button" title="No previous voucher"><b>‹</b><span>Previous</span></button>@endif
    @if(($mode ?? '') === 'view' && $nextOrder)<a class="voucher-action" href="{{ route('orders.show', [$module, $nextOrder->id]) }}" title="Open next voucher"><b>›</b><span>Next</span></a>@else<button class="voucher-action" disabled type="button" title="No next voucher"><b>›</b><span>Next</span></button>@endif
    @if($mode === 'view')<button class="voucher-action" type="button" data-open-move><b>⇄</b><span>Move</span></button>@else<button class="voucher-action" disabled type="button"><b>⇄</b><span>Move</span></button>@endif
    <a class="voucher-action" href="/{{ $module }}"><b>×</b><span>Exit</span></a>
    @if($mode === 'create' || $mode === 'edit')<button class="voucher-action save-action" type="submit" form="order-form"><b>✓</b><span>Save</span></button>@else<button class="voucher-action save-action" disabled type="button"><b>✓</b><span>Save</span></button>@endif
</nav>
