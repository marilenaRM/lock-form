# Concurrent edits on forms: design notes and prototype

## 1. The problem

Our dashboard exposes edit forms. Alice and Bob open the same record a few minutes apart, and Bob saves
first. Alice still has the old version on screen, so when she saves she overwrites Bob's change without
knowing it, and nobody is told.

This is the classic *lost update*. It has nothing to do with a slow query or two processes racing each
other: the dangerous window is the human time between opening the form and submitting it, which is minutes
rather than milliseconds.

What we wanted:

- **automatic**, like CSRF protection: an existing form gets protected without anyone touching its type;
- **generic**: nothing may depend on Doctrine, so a DTO, an object coming from an API or a plain array all
  behave the same way;
- **usable when a conflict happens**: the user keeps what they typed and can see which fields moved.

## 2. Options we looked at

### A. A lock on the controllers (pessimistic locking): rejected after analysis

The idea is that opening the form locks the record, and nobody else can edit it until it is released.

Over HTTP, a lock that lasts as long as a user is thinking is hard to manage. An abandoned tab keeps the
record locked, so you need a TTL. A long edit must not lose the lock, so you need a JavaScript heartbeat.
`beforeunload` cannot be trusted to release it, which means you end up relying on the TTL anyway. And you
need a "take over the lock" button for the admin whose colleague went home with the tab still open.

On top of all that, it does not protect the data: once a lock has expired or been taken over, the overwrite
goes through. You still need a check at submit time.

Where it does help is UX. Telling someone *before* they start typing that "Bob is editing this record"
avoids the conflict instead of reporting it. If that need turns out to be real, it can be built on top of
the submit-time check as a non-blocking presence indicator (Symfony Lock with a TTL, or Mercure). We did not
prototype it.

### B. Doctrine optimistic locking (`#[ORM\Version]`): kept as a complement, not as the base

Doctrine already does this. A version column, and an `UPDATE … WHERE version = ?` that throws an
`OptimisticLockException` when someone got there first. It is the only option here that is truly atomic.

It does not fit what we were after, though:

- it only works for Doctrine entities, and needs a column and a migration for each of them;
- the version changes on every write to the entity, including writes to fields the form does not show,
  which gives false conflicts;
- the version still has to travel through the form in a hidden field, so we would need the same form-side
  mechanism anyway.

We did not test it in this prototype because the development machine lacks `pdo_sqlite`. The points above
come from how Doctrine is documented to behave, not from a measurement.

### C. Hashing the object in `PRE_SET_DATA`: rejected, and tested

This was the starting idea: when the form receives its data, compute a hash of the object, put it in a
hidden field, and compare it again on submit.

It has two problems, and a test in `tests/Exploration/` demonstrates each of them.

1. **`PRE_SET_DATA` comes too early.** On the root form it is dispatched before the data mapper fills in the
   fields (in `Form::setData()`, the event fires before `mapDataToForms()`), so at that point every field is
   still empty. See `PreSetDataIsTooEarlyTest`.
2. **Hashing the object measures the wrong thing.** The hash changes when a property that is not in the form
   changes, which is a false conflict. It also depends on how a value is stored rather than on what it
   means: the same date in `UTC` and in `Z` gives two different hashes. Then come lazy proxies and
   collections, circular references, and related objects you cannot identify without knowing the ORM.
   See `ObjectHashTest`.

### D. A fingerprint of what the form displays: the option we kept

We kept the idea of a hidden field computed automatically, but changed *what* gets hashed and *when*.

What: the *view data* of each editable leaf field, meaning the value as the form displays it, after its own
data transformers. By then dates are already in the widget's format, numbers are formatted, a related object
has been reduced to its choice value (its id) and an enum to its scalar. Two loads of the same state always
give the same fingerprint, and the code never needs to know where the data is stored.

When: in `POST_SET_DATA` on the root form, once every field has been filled in, and again in `PRE_SUBMIT`,
before the fields receive the submitted values.

There is a useful side effect: we only compare what the user was able to change. A concurrent change to a
field that is outside the form, or disabled, is not a conflict, since this user cannot overwrite it.

## 3. How it works

```
GET  /article/42/edit                       POST /article/42/edit
  controller: find(42)                        controller: find(42)  ← current state in storage
  createForm($article)                        createForm($article)
    POST_SET_DATA (root)                      handleRequest()
      fingerprint F1 = what is displayed         PRE_SUBMIT (root), before the fields
  render: <input hidden _concurrency_token=F1>     fingerprint F2 = what is stored now
                                                   received F1 ≠ F2? → conflict, changed paths
                                                 fields receive the submitted values
                                                 POST_SUBMIT (root)
                                                   global error + an error on each changed field
```

| Event | Priority | Why |
|---|---|---|
| `POST_SET_DATA` | -1024 | After the form's own listeners, so that fields they add are included. |
| `PRE_SUBMIT` | 1024 | Before the form's own listeners, which may rebuild fields from the submitted values (dependent fields). |
| `POST_SUBMIT` | 0 | A field clears its errors when it is submitted, so field errors can only be attached afterwards. |

The token is a JSON object encoded in base64url, `{"path": hash}` for each field, with paths such as
`author.email` or `tags.1`. Keeping the paths is what lets us say *which* fields changed. The hash is a
truncated HMAC-SHA256 keyed with `kernel.secret`. The key is not there to prevent tampering (see the limits
below) but to protect values that are never displayed: a `PasswordType` with `always_empty` still has view
data, and with a fast unkeyed hash the token would let anyone recover that password by brute force,
offline. The code review of the prototype caught this, and it has been fixed.

When there is a conflict, the form is invalid and carries:

- a global error (`form_concurrency.conflict`) explaining what happened;
- an error on each field that changed in the meantime, saying what that field holds now:
  `form_concurrency.field_changed_to` with a `{{ value }}` parameter, or `form_concurrency.field_cleared`
  when someone emptied it.

The current value is the one the field would display, read while the form still holds the stored state,
just before the submitted values are applied. To get labels and translation domains right, the listener
renders the form at that point, which only happens when there is a conflict. A choice therefore shows its
label ("Tutorial") rather than its value ("2"). A field that never renders its value, like a `PasswordType`,
only gets the plain `form_concurrency.field_changed` message: the conflict must not disclose what the form
itself hides. Checkboxes and radios get it too, since their rendered value is not what the user sees. An entry added to or removed from a collection meanwhile is not in the submitted collection,
so the collection itself carries the error, with `form_concurrency.entries_changed`. That message names the
field (`{{ field }}`), because a collection bubbles its errors up to the top of the form by default.

The form shown again keeps what the user typed, and it also gets a checkbox: "I have reviewed these changes:
save my values anyway". Submitting the form again without ticking it is still a conflict, because the token
it carries is still the one the user started from. Ticking it is what settles the conflict, and only for the
state that was shown: the box carries that state's token, so if someone saves again in between, the user gets
a new conflict and a new box. A missing or unreadable token is treated as a conflict, the same way a missing
CSRF token is.

The box is rendered with the rest of the form by `form_end()`. Most templates render the submit button
themselves, so they will want to place it just before the button:

```twig
{% if form._concurrency_token_acknowledged is defined %}
    {{ form_row(form._concurrency_token_acknowledged) }}
{% endif %}
```

The first version worked differently: the re-rendered form carried the token of the current state, so
submitting it again was the acknowledgement. That broke with LiveComponent. `ComponentWithFormTrait` submits
the form on every re-render, that is every time the user types, then reads the form values back from the
rendered view, the token included. The first keystroke after someone else's save therefore swapped the token
for the current one, and the next save overwrote the other person's change without a word. A test in the
demo (`LiveComponentConflictTest`) reproduced it before the fix; the demo now has the LiveComponent version of
the form side by side with the classic one, and `e2e/live.mjs` plays the scenario in a browser. An explicit acknowledgement fixes it for
LiveComponent, and is also clearer for a classic form: sending the same form again by reflex no longer counts
as "I reviewed it".

To turn it on in a Symfony application, enable `FormConcurrencyBundle`. Every compound root form is then
protected, except GET forms. Those are searches and filters: they change nothing, and people reach them
through links (pagination, menus, bookmarks) that carry no token. You can turn it off for one form with the
`concurrency_protection: false` option, or everywhere with `form_concurrency.enabled: false`.

To find out how often conflicts happen, turn on `form_concurrency.conflict_event`. Every refused submission
then dispatches a `ConcurrencyConflictEvent` carrying the form and the changed paths, in `POST_SUBMIT` once the
errors are attached, so a listener sees the form exactly as the user will. It is off by default; when it is
off, the listener does not even get the event dispatcher. One edit can produce several events: a conflict sent
again without acknowledging it is refused again, and a LiveComponent submits the form on every re-render, so
every keystroke during a conflict is a refused submission. Counting events would measure refused submissions,
not conflicts. The event therefore carries `seenToken`, the token the user started from, which is the same for
all of them. It is a fingerprint of the state the user saw, so it identifies neither the user nor the record:
two people who opened the same version share it, and so do two records whose fields hold the same values. To
count each conflict once, count distinct (session, URL, `seenToken`) triples. A submission without a token
(`seenToken` null, no changed paths) is not a concurrent edit and should be counted apart. An acknowledged
save is not a conflict and dispatches nothing.

## 4. What the tests cover

`composer qa` passes: PHPStan at level 9 with no errors, and 141 green tests. To make sure the tests can
actually fail, I disabled the detection by hand (`conflict = false`) and 22 tests failed. Moving the
`PRE_SUBMIT` priority to -1024 makes the dependent-field test fail.

| Case | Test |
|---|---|
| Token added to the root form only | `testItAddsTheTokenToTheRootFormOnly` |
| No concurrent change → accepted | `testASubmissionOnAnUnchangedRecordIsAccepted` |
| A field of the form changed → global error, plus an error on that field only, with its current value | `testAConcurrentChangeToAFieldOfTheFormIsReportedOnTheFormAndOnThatField` |
| A field emptied meanwhile → reported as cleared | `testAFieldEmptiedConcurrentlyIsReportedAsCleared` |
| A checkbox toggled meanwhile → reported, without a value | `testACheckboxToggledConcurrentlyIsReportedWithoutAValue` |
| A field that never renders its value (`PasswordType`) → reported, value not disclosed | `testTheCurrentValueOfAFieldThatNeverRendersItIsNotDisclosed` |
| A field in an embedded form (`author.email`) | `testAConcurrentChangeInsideAnEmbeddedFormIsReportedOnTheNestedField` |
| A related object (choice of objects, new instance on every load), shown by its label | `testAConcurrentChangeToARelatedObjectIsReported` |
| Property outside the form, disabled field, same value saved again → no conflict | `testAConcurrentChangeTheUserCannotOverwriteIsNotAConflict` |
| `CollectionType`: entries added and removed by the user → no conflict | `testEntriesAddedOrRemovedByTheUserInACollectionAreNotAConflict` |
| `CollectionType`: entry added by someone else → conflict, reported on the collection | `testAnEntryAddedConcurrentlyToACollectionIsAConflict` |
| Sending back the form rendered after a conflict, as is → still a conflict (what a LiveComponent does on every re-render) | `testSubmittingTheFormRenderedAfterAConflictAgainIsStillAConflict` |
| The acknowledgement box appears only with a conflict, unticked | `testTheAcknowledgementBoxIsOnlyRenderedWithAConflict` |
| Ticking it → accepted, the user's values are kept | `testAcknowledgingTheConflictSavesTheUserValues` |
| An acknowledgement only covers the state it was shown for → a later change is a new conflict | `testAnAcknowledgementOnlyCoversTheStateItWasGivenFor` |
| A forged acknowledgement is ignored | `testAForgedAcknowledgementIsIgnored` |
| Token missing, empty, not base64, not JSON, malformed → conflict, no exception | `testASubmissionWithoutAUsableTokenIsRejected` |
| The token does not show up as extra data | `testTheTokenFieldIsNotReportedAsExtraData` |
| Turned off for one form | `testTheProtectionCanBeTurnedOffForOneForm` |
| GET form (filter) not protected | `testAGetFormIsNotProtected` |
| Token cannot be matched without the secret | `testTheTokenCannotBeMatchedWithoutTheSecret` |
| Dependent field rebuilt in `PRE_SUBMIT` → no false conflict | `testAFieldRebuiltFromTheSubmittedValuesIsNotAConflict` |
| Token round trip, and detection of a single changed field, over 50 generated forms (fixed seed, invalid UTF-8 included) | `FingerprintTest` |
| Conflict event: changed paths, dispatched once the errors are on the form, the same `seenToken` for every refusal of one edit, nothing for an acknowledged one | `ConcurrencyConflictEventTest` |
| Bundle: protection without touching the type, messages translated into French, conflict event only when turned on | `FormConcurrencyBundleTest` |

## 5. Known limits

- **It detects, it does not guarantee.** The check runs in PHP, before the write, so two submissions
  handled at the same instant both pass: neither has saved yet when the other one checks
  (`CheckThenWriteRaceTest`). That window is a few milliseconds, while the real problem spans minutes.
  Closing it takes atomicity from the storage, i.e. `#[ORM\Version]` on top, where it is worth it.
- **The object must be reloaded on every request.** The comparison only means something if the data given
  to the form on submit is the current stored state. A regular controller that calls `find()` does that. An
  object kept in the session does not: the detection would see nothing. A LiveComponent is fine as long as the
  edited record is a `LiveProp`, which is reloaded from the database on every request (verified in the demo).
- **It is not a security feature.** A user who wants to bypass it only has to reload the page to get a
  fresh token. It protects users from each other's mistakes, not from someone acting in bad faith, who
  could overwrite the data without this feature anyway.
- **A form in conflict has still modified the object in memory**, like any invalid form. The controller must
  only save when `isValid()` returns true, which is already the rule.
- **Token size** is about 40 characters per field (322 characters for the 8 fields of the test form).
  That is nothing for a back-office form, but worth watching for a collection with hundreds of entries.
- **Fields whose view data is an object** (`FileType`) are ignored.
- **Partial submissions** (`$form->submit($data, false)`, typically a `PATCH`): a field missing from the
  payload is not overwritten, yet a concurrent change to it is still reported. We cannot safely leave it
  out, because the event does not expose `clearMissing`, and an unchecked checkbox is missing from the
  payload too even though it does overwrite the value. Turn the option off for those cases.
- **Clients that never render the form** (APIs, functional tests that submit an array) have to send the
  token back or turn the option off, exactly as with CSRF.
- **In a LiveComponent, the conflict shows up on save, not while typing.** The component does detect it on the
  first re-render, but `ComponentWithFormTrait` clears the errors of every field the user has not touched yet,
  the form itself included. The acknowledgement box does appear while typing; the explanation comes with the
  save, which is refused until the box is ticked. The form's own CSRF token is usually off in a LiveComponent,
  which has its own same-origin check.
- **Choice fields rendered as checkboxes or radios** (`expanded: true`) report the conflict on the individual
  checkbox rather than on the whole field.

## 6. Next steps

1. Try the prototype on a real dashboard form, with `conflict_event` turned on to count the conflicts. Doctrine
   and `EntityType` are covered by the demo; a real form will tell whether its own types and listeners hold up.
2. If the figures confirm the need, add the non-blocking presence indicator (option A) on top.
