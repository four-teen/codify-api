# Python written assessments

Apply `database/python-written-attempts.sql` before deploying this workflow to another database. Locally it can be applied with `php database/apply-python-written-attempts.php`. Both tables are created idempotently.

The student runner route has been removed. Answers are stored as text only; no code is executed. Faculty can open **Problem bank → Actions → Student answers** to review submitted code. Automatic grading and faculty scoring are deferred.

Students start explicitly after reading the rules. Active attempts close on clipboard actions, dropped text, hidden tabs, window blur, navigation, page closure, or disconnection. Each close clears the in-memory editor. No answer draft is stored in browser storage or sent to the API before submission. Submission is final. Three closes lock that student/problem pair across devices; refreshing or clearing browser storage cannot reset the database counter.

The server uses a row lock and a per-attempt random token. Duplicate lifecycle events count once; stale pages cannot close or submit a newer attempt. Starting while another attempt is active ends the old attempt and counts one close. A 15-second heartbeat maintains the session; sessions with no heartbeat for 90 seconds cannot submit. Missed shutdown events are recovered at the next start. The browser retains only pending close metadata to retry a failed close request.

Browser lifecycle and clipboard events provide deterrence, not a trusted proof of student behavior. Mobile operating systems can terminate browsers without delivering an event. Deliberate browser modification, another device, or external assistance cannot be prevented by a web editor alone. Window blur is intentionally strict and can also occur when using browser UI or operating-system dialogs.

The CodeMirror editor is bundled locally with `npm run build:editor` so it works on Apache without runtime CDN dependencies. `npm run build` regenerates this bundle. It supports Python highlighting, line numbers, four-space indentation, bracket matching, paired brackets and quotes, undo/redo, and mobile indent/outdent buttons.

Verification: `php tests/problem-work-smoke.php`, `php tests/problem-output-visibility-smoke.php`, and `node tests/python-workspace-browser-smoke.mjs` (isolated Chrome debugging port 9341).
