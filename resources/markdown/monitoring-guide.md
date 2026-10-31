# Monitoring guide for the team

## Start here

| What is wrong? | Open |
| --- | --- |
| Jobs are waiting or failing | [Horizon](https://portal.coiiot.in/horizon/dashboard) |
| A page or job has an error | [Nightwatch](https://nightwatch.laravel.com/eu/environments/a22f9492-f3f1-4e7d-81cf-773cb5ab8545/queries?period=1h) |
| A job is slow or uses too much memory | [Queue Monitor](/queue-monitor) |
| Scheduled work may have stopped | Healthchecks.io |

Choose the correct time range. An empty chart does not always mean everything is healthy.

## Horizon: dashboard walkthrough

## Nightwatch: dashboard walkthrough

## Healthchecks.io: check the scheduler

[Open Healthchecks.io](https://healthchecks.io/)

**What it does:** Watches for a regular “I am running” signal from the scheduler. If the signal stops arriving, it can alert the team.

**How the app uses it:** The scheduler is configured to send a signal every minute when its private ping URL is set. The external check and alert recipients must also be configured.

| Status | Meaning |
| --- | --- |
| Up | The expected signal arrived on time. |
| Late | The signal is overdue, but the extra waiting time has not expired. |
| Down | The signal missed the allowed time. Investigate the scheduler or connection. |
| Paused | Monitoring is temporarily switched off for this check. |

**Period** is how often a signal is expected. **Grace time** is the extra delay allowed before an alert.

**If it goes down:** Check the last signal, scheduler and network connection. After fixing the problem, confirm a new signal arrives.

**Remember:** A green check confirms the heartbeat arrived—not that every factory job or report completed. Alert delivery depends on the configured notification settings.
