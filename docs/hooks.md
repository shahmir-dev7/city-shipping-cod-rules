# Hooks

## Filters

### `cityrules_rule`
The rule found for a destination, or `null` when there is none. Use it to apply rules from another source or to adjust one.

```php
add_filter( 'cityrules_rule', function ( $rule, $country, $city ) {
	if ( null === $rule && 'PK' === $country ) {
		return array( 'shipping_cost' => 350.0, 'free_over' => null, 'cod' => 'default', 'cod_fee' => null );
	}
	return $rule;
}, 10, 3 );
```

| Param | Type | Description |
|---|---|---|
| `$rule` | array\|null | Keys: `shipping_cost`, `free_over`, `cod` (`default`, `yes`, `no`), `cod_fee`; amounts are floats or `null` for "not set". |
| `$country` | string | Destination country code. |
| `$city` | string | Destination city as typed by the customer. |

The result is cached for the rest of the request.

## How rules combine
- A rule for the customer's country beats a rule with no country.
- Shipping: the city's cost, or `0` once the cart value reaches its "free over" amount. A city with no cost, or no matching rule, gets the shipping method's "cost for cities not in the table", or no rate when that is empty.
- COD: only an explicit `no` removes Cash on Delivery.
- COD fee: the city's fee (an explicit `0` means none), else the store-wide fee.