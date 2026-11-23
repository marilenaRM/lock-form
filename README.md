# Form concurrency protection

Prototype. Detects that a record changed between the moment a form was displayed and the moment it was
submitted, so that a user does not silently overwrite someone else's changes. It works like the CSRF
protection: a hidden token is added to every root form and checked on submit, with no change to form types
and no dependency on Doctrine.

The reasoning, the options that were considered and the limits are in [docs/design.md](docs/design.md).

![Two people editing the same record, without then with the protection](docs/demo.gif)

To see it working, [`demo/`](demo/README.md) is a small back-office where two people edit the same product
side by side: `cd demo && docker compose up --build`.

## Usage

Enable the bundle:

```php
// config/bundles.php
return [
    // ...
    LockForm\FormConcurrency\FormConcurrencyBundle::class => ['all' => true],
];
```

Every root form is now protected, except GET forms (searches, filters). On a conflict the form is invalid, with a global error and an error on
each field that changed meanwhile, showing what it holds now, and a checkbox to save the user's values anyway. Submitting again without ticking
it is still a conflict. The box is rendered by `form_end()`; to put it next to your submit button:

```twig
{% if form._concurrency_token_acknowledged is defined %}
    {{ form_row(form._concurrency_token_acknowledged) }}
{% endif %}
```

It works the same way in a LiveComponent using `ComponentWithFormTrait`, as long as the edited record is a
`LiveProp` (reloaded from the database on every request).

The controller must load the record on every request, which a regular edit action already does:

```php
#[Route('/article/{id}/edit')]
public function edit(Article $article, Request $request): Response
{
    $form = $this->createForm(ArticleType::class, $article)->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
        $this->entityManager->flush();

        return $this->redirectToRoute('article_show', ['id' => $article->getId()]);
    }

    return $this->render('article/edit.html.twig', ['form' => $form]);
}
```

Options:

| Where | Option | Default |
|---|---|---|
| form | `concurrency_protection` | `true` |
| form | `concurrency_field_name` | `_concurrency_token` |
| `config/packages/form_concurrency.yaml` | `enabled` | `true` |
| `config/packages/form_concurrency.yaml` | `field_name` | `_concurrency_token` |

Messages use the `form_concurrency` translation domain (English and French provided).

Outside a Symfony application:

```php
$factory = Forms::createFormFactoryBuilder()
    ->addExtension(new FormConcurrencyExtension($secret, $translator))
    ->getFormFactory();
```

## Development

```bash
composer install
composer qa
```
