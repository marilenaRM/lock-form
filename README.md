# Form concurrency protection

Prototype. Detects that a record changed between the moment a form was displayed and the moment it was
submitted, so that a user does not silently overwrite someone else's changes. It works like the CSRF
protection: a hidden token is added to every root form and checked on submit, with no change to form types
and no dependency on Doctrine.

The reasoning, the options that were considered and the limits are in [docs/design.md](docs/design.md).

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
each field that changed meanwhile, showing what it holds now. Rendering it again carries a fresh token: submitting it again keeps the
user's values.

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
