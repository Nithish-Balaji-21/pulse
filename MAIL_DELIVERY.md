# Summary email delivery

New production-summary jobs share a sending gate across Horizon workers. By default,
one summary attempt is allowed every 60 seconds. This is application pacing, not a
Zoho-approved allowance. A message may have multiple recipients; other applications,
OTPs, account emails and synchronous alerts also consume the mailbox's allowance.

Zoho's `550 5.4.6` response defers summary delivery for one hour across these workers.
Messages remain queued during that cooldown. Other failures use 5, 15 and 60 minute
backoff, with at most three unhandled exceptions. Jobs have a fixed 24-hour deadline;
expired jobs remain visible in failed jobs for review rather than retrying indefinitely.
No email is sent by the unit tests.

## Deployment

- Use the same Redis cache across all workers (`MAIL_DELIVERY_CACHE_STORE=redis`).
- Optional settings: `MAIL_SUMMARY_INTERVAL_SECONDS=60` and
  `MAIL_BLOCK_COOLDOWN_SECONDS=3600`.
- Rebuild Laravel's configuration cache and gracefully restart Horizon after deployment
  using the normal deployment process. Verify Redis is available first.
- Existing serialized jobs do not gain new middleware automatically. Do not bulk retry
  old jobs. Review still-needed failed summaries individually before regenerating them
  through the normal summary command; regenerating can duplicate already-delivered mail.
- Check Horizon and Zoho delivery logs for a newly generated summary before releasing
  any backlog. Watch for increasing pending jobs or deadline failures.

This change does not change SMTP credentials, OTP delivery, alert state transitions,
or report contents. It does not deduplicate independently scheduled summaries or
guarantee prevention of provider blocks. Zoho Mail's policy excludes automated mail;
confirm permitted usage with Zoho support.
