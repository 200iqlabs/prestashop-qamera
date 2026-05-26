## MODIFIED Requirements

### Requirement: registerImage accepts optional product_metadata for cascading product creation

`QameraApiClient::registerImage(RegisterImageRequest $request)` SHALL serialize an optional `product_metadata` object into the `POST /plugin/images` request body when the request DTO carries one. The shape of `product_metadata` SHALL match the upstream `ProductMetadataSchema`:

- `display_name`: required string, max 500 characters
- `sku`: optional string, max 100 characters
- `description`: optional string, max 5000 characters

When `product_metadata` is present and the `product_ref` does not already correspond to a registered upstream product, the upstream SHALL cascade-create the product and return `product_id` in the response. When `product_metadata` is omitted, the upstream SHALL look up an existing product by `product_ref` and reject the request with 404 if none exists.

The client SHALL build `RegisterImageRequest` from a new constructor parameter `?ProductMetadata $productMetadata = null`. The `ProductMetadata` value object SHALL validate the size constraints in its constructor (`InvalidArgumentException` on violation) so callers cannot construct an invalid payload at runtime. The DTO SHALL live at `QameraAi\Module\Api\Dto\ProductMetadata` so it is reusable by `RegisterPackshotRequest` in future phases.

#### Scenario: registerImage with product_metadata cascades upstream product creation

- **GIVEN** a `RegisterImageRequest` constructed with `product_ref='ps:1:42'`, `source_url='https://qamera-uploads.example/...'`, `productMetadata=new ProductMetadata('Widget', 'WDG-001', 'hello')`
- **WHEN** `QameraApiClient::registerImage` is called
- **THEN** the HTTP request body to `POST /plugin/images` is `{product_ref: 'ps:1:42', source_url: '...', product_metadata: {display_name: 'Widget', sku: 'WDG-001', description: 'hello'}}`

#### Scenario: registerImage without product_metadata omits the field

- **GIVEN** a `RegisterImageRequest` constructed with `productMetadata=null`
- **WHEN** the client serializes the payload
- **THEN** the JSON body has no `product_metadata` key (not even `null` — the key is absent)

#### Scenario: ProductMetadata rejects oversize display_name

- **WHEN** `new ProductMetadata(str_repeat('a', 501))` is called
- **THEN** the constructor throws `InvalidArgumentException` with a message identifying the field and the max length

#### Scenario: ProductMetadata rejects oversize sku

- **WHEN** `new ProductMetadata('Widget', str_repeat('a', 101))` is called
- **THEN** the constructor throws `InvalidArgumentException`

#### Scenario: ProductMetadata rejects oversize description

- **WHEN** `new ProductMetadata('Widget', 'WDG', str_repeat('a', 5001))` is called
- **THEN** the constructor throws `InvalidArgumentException`
