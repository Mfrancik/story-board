# ADR-013 — The project-page refusal is a middleware that runs before route binding

Date: 2026-09-29 · Status: accepted

## Context

SB-7 adds `/p/{project:name}` and needs a refusal for an unknown or disabled project. The refusal
must live in one place, be reused by SB-4's story route, and log why it refused (L-5). Livewire 4
does `{project:name}` route-model binding inside `SubstituteBindings`. So an unknown name 404'd there,
silently, before any route middleware or `mount()` could log it. SB-4 had a disabled-project check
inside `StoryPage::mount()`. That caught `disabled` but never `unknown`.

## Decision

`app/Http/Middleware/EnsureProjectIsShown.php` is attached to `projects.show` and `stories.show`. It
reads the raw route parameter, asks `app/Actions/Board/CheckProjectShown.php:refusal()`, logs
`board.project_page_refused {project, reason}` and aborts 404. `bootstrap/app.php` calls
`prependToPriorityList(SubstituteBindings::class, EnsureProjectIsShown::class)`, so it runs before
binding. `StoryPage`'s own disabled branch was removed. `RedirectProjectFilter` (the old `/?project=`
URL) reuses the same `CheckProjectShown`.

Alternatives rejected:
- **`Route::bind('project', …)`.** It is global, so it would also change the `mockups.file` route and
  its `board.mockup_not_found` guard.
- **`->missing()` on each route.** That handles only "not found", so the disabled check would still
  live somewhere else. The refusal would be split in two.
- **Override `Project::resolveRouteBinding()`.** Also global, with the same problem as `Route::bind`.

## Consequences

- Both refusals log, and both have a test (`AppShellTest`, `GuardLoggingTest`).
- A disabled project's story page now logs `board.project_page_refused` instead of
  `board.story_not_found` with `reason: project disabled`.
- Any new route under `/p/{project}` has to attach the middleware itself. The priority entry applies
  only to routes that use it.
- The guard runs one small query, and binding then loads the project again. The duplicate lookup is
  accepted.
