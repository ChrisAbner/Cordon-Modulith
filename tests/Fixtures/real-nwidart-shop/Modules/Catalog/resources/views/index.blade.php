@php($orders = \Modules\Orders\Models\Order::all())
<div>{{ $orders->count() }}</div>
