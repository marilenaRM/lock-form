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
it is still a conflict. A field the user sets to the value it holds now is no longer a conflict, since saving it overwrites
nothing: if no other field is, the save goes through without the box. The box is rendered by `form_end()`; to put it next to your submit button:

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
| form | `concurrency_message` | `form_concurrency.conflict` |
| `config/packages/form_concurrency.yaml` | `enabled` | `true` |
| `config/packages/form_concurrency.yaml` | `field_name` | `_concurrency_token` |
| `config/packages/form_concurrency.yaml` | `conflict_event` | `false` |
| `config/packages/form_concurrency.yaml` | `test_bypass` | `false` |

To count conflicts, for instance before deciding whether the [presence indicator](#presence-indicator-optional) is worth it, turn on
`conflict_event`. Each refused submission then dispatches a `ConcurrencyConflictEvent`, once the errors are on
the form:

```yaml
# config/packages/form_concurrency.yaml
form_concurrency:
    conflict_event: true
```

```php
use LockForm\FormConcurrency\Event\ConcurrencyConflictEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsEventListener]
final class CountConcurrencyConflicts
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function __invoke(ConcurrencyConflictEvent $event): void
    {
        $request = $this->requestStack->getMainRequest();

        $this->logger->info('Concurrent edit on {form}', [
            'form' => $event->form->getName(),
            'fields' => $event->changedPaths,
            // The same for every refusal of one edit: count distinct values to count each conflict once.
            // No token at all is not a concurrent edit (an API client, a page cached before the deployment).
            'edit' => null === $event->seenToken ? null : hash('xxh3', implode("\0", [
                $request?->getSession()->getId(),
                $request?->getPathInfo(),
                $event->seenToken,
            ])),
        ]);
    }
}
```

`changedPaths` lists the property paths changed meanwhile (`price`, `author.email`); it is empty when the
submission carried no usable token. The same edit can be refused several times: sent again without
acknowledging the conflict, or re-rendered by a LiveComponent, which submits the form on every change.
`seenToken`, the token the user started from, is the same for all of them. It identifies the state the user
saw, not the user nor the record: two people who opened the same version get the same token, hence the
session and the URL in the key above. An acknowledged save is not a conflict and dispatches nothing.

Messages use the `form_concurrency` translation domain (English and French provided).

The global error is a `ConcurrencyConflictError`, a `FormError` subclass, so a template or a controller can tell
it apart from the other errors, to show it as an alert or a flash message. The errors on each changed field stay
plain `FormError`s, like any validation error next to its field. To change the global message of one form, set
`concurrency_message` to another key of the `form_concurrency` domain, or to a `TranslatableInterface` such as
`new TranslatableMessage('article.conflict', [], 'admin')`.

Outside a Symfony application:

```php
$factory = Forms::createFormFactoryBuilder()
    ->addExtension(new FormConcurrencyExtension($secret, $translator))
    ->getFormFactory();
```

Pass `dispatcher: $eventDispatcher` to `FormConcurrencyExtension` to get the conflict event there too.

### Changing what counts as a conflict

The decision is a stateless `ConflictDetector` service: from the fingerprint the user saw and the one of the
stored state, it returns the paths changed meanwhile, `[]` to accept the submission, or `null` to refuse it
without a field to report (no usable token). Decorate it, for instance to let the last write win on a field:

```php
use LockForm\FormConcurrency\ConflictDetector;
use LockForm\FormConcurrency\Fingerprint;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Form\FormInterface;

#[AsDecorator(ConflictDetector::class)]
final class ViewCountIsLastWriteWins implements ConflictDetector
{
    public function __construct(#[AutowireDecorated] private readonly ConflictDetector $inner) {}

    public function changedPaths(FormInterface $form, ?Fingerprint $seen, Fingerprint $current): ?array
    {
        $changed = $this->inner->changedPaths($form, $seen, $current);

        return null === $changed ? null : array_values(array_diff($changed, ['viewCount']));
    }
}
```

Outside a Symfony application, pass `detector: $detector` to `FormConcurrencyExtension`.

### In functional tests

A test that posts an array instead of submitting the rendered form sends no token, so it gets a conflict, as it
would without a CSRF token. Either turn the protection off for the whole test environment:

```yaml
# config/packages/form_concurrency.yaml
when@test:
    form_concurrency:
        enabled: false
```

or keep it on, so that tests can still check it, and let the others go through with `test_bypass`:

```yaml
when@test:
    form_concurrency:
        test_bypass: true
```

```php
use LockForm\FormConcurrency\Test\BypassConflictDetector;

$client->setServerParameter(BypassConflictDetector::SERVER_PARAMETER, '1'); // every request of this client
$client->request('POST', '/article/42/edit', $data, server: [BypassConflictDetector::SERVER_PARAMETER => '1']); // one request
```

The switch is a server parameter rather than a flag on a service: `KernelBrowser` reboots the kernel between two
requests by default, with a new container, so a flag would be gone by the second request. A new client starts
without it, so nothing carries over to the next test. A browser cannot send it either, since its headers only
reach the request as `HTTP_*` server parameters. Do not set it as an environment variable or in a `.env` file,
though: those end up in the server parameters of every request. And keep `test_bypass` under `when@test`.

## Presence indicator (optional)

The token above reports a conflict when the second person saves. The presence indicator warns earlier, while
both are still typing: "Bob is also editing this record.", shown above the form. It is a hint, not a lock: it
blocks nothing, and the token is still what protects the data.

### Is it worth it?

Measure first. Turn on `conflict_event` and count the conflicts for a few weeks, once per edit (distinct
session, URL and `seenToken`, leaving tokenless submissions aside), next to the number of saves of the same
forms. Then:

- **conflicts are rare** (a handful a month on a back office): stay with the token alone. Each conflict costs
  one review of the fields it lists, which is cheaper than a heartbeat from every open form;
- **conflicts are frequent, or concentrated on a few forms**, or each one is costly (long forms, input typed
  from paper, people who lose their changes rather than review them): turn the indicator on, and only for
  those forms if they are few. Check afterwards that the conflict count goes down: if it does not, people
  ignore the banner and it is not worth its requests.

There is no universal threshold: these figures are a starting point to discuss, not a measurement.

### Turning it on

It is off by default. Off, the bundle registers no service and no route, and renders nothing more.

```yaml
# config/packages/form_concurrency.yaml
form_concurrency:
    presence:
        enabled: true
        # cache_pool: cache.app      where open tabs are kept
        # identity: ~                a service implementing PresenceIdentity; the logged-in user by default
        # interval: 15               seconds between two heartbeats of a tab
        # ttl: 90                    seconds without a heartbeat before a tab counts as closed
```

```yaml
# config/routes/form_concurrency.yaml
form_concurrency:
    resource: '@FormConcurrencyBundle/config/routes.php'
```

```twig
{# in the layout, once: AssetMapper serves it as is, otherwise run assets:install #}
<script src="{{ asset('bundles/formconcurrency/presence.js') }}" defer></script>
```

The script has no dependency. It adds a `<p class="concurrency-presence" role="status">` before the form;
style it as you like.

It needs `symfony/cache` (any PSR-6 pool) and `symfony/routing`. Without a custom identity, it also needs
`symfony/security-bundle`, and only tracks logged-in users, named by their user identifier. To show a full
name, or to track people without a login, implement `PresenceIdentity`:

```php
use LockForm\FormConcurrency\Presence\PresenceIdentity;
use LockForm\FormConcurrency\Presence\PresenceViewer;

final class StaffPresenceIdentity implements PresenceIdentity
{
    public function __construct(private readonly Security $security) {}

    public function viewer(Request $request): ?PresenceViewer
    {
        $user = $this->security->getUser();

        // The id groups the tabs of one person; the name is what the others read.
        return $user instanceof StaffMember ? new PresenceViewer((string) $user->getId(), $user->getFullName()) : null;
    }
}
```

Form options:

| Option | Default | |
|---|---|---|
| `concurrency_presence` | `true` when turned on | `false` to leave one form out |
| `concurrency_presence_key` | the path of the page | the record, when several pages edit it, or in a LiveComponent |

People are on the same record when their pages have the same path (`/article/42/edit`), the query string left
out. Name the record yourself when two pages edit it, and always in a LiveComponent, whose re-renders come from
another URL:

```php
$this->createForm(ArticleType::class, $article, ['concurrency_presence_key' => 'article-'.$article->getId()]);
```

The attributes go on the `<form>` tag through `form.vars.attr`. A template that passes its own `attr` to
`form_start()` replaces them; merge instead: `form_start(form, {attr: form.vars.attr|merge({...})})`.

### How it works, and its limits

The page renders a token signed with `kernel.secret`, holding the record key and the viewer. Every `interval`
seconds, each tab posts it to `/_form_concurrency/presence` and gets back who else has the record open; when the
tab is closed or left, it says so with `sendBeacon`. The identity is resolved when the form is rendered, so the
heartbeat needs neither the session nor the firewall, and cannot be used to ask about another record or to
pose as someone else.

- The list is kept in one cache item per record. Two heartbeats at the same instant can drop one tab until its
  next heartbeat: fine for a hint, which is all this is.
- A tab that disappears without a word (crash, lost network) stays listed for up to `ttl` seconds. Browsers slow
  down the timers of background tabs to about one a minute, hence the 90 s default: lower it and people who
  only switched tabs flicker out.
- It tells who has the form open, not who is typing nor who will save.
- Every open form costs one request per `interval`. With 50 people each keeping one form open, that is about
  3 requests a second, each a cache read and write.
- Displayed names go to everyone who opens the same record. Use names your users already see of each other.
- A token stays valid as long as the secret: someone can keep showing up on a page they once had the right to
  open, until they close it.

## Development

```bash
composer install
composer qa
```
