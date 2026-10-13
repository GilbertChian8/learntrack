# Load check

`launch-check.js` measures the launch targets of [ticket 15](../../docs/tickets/15-launch-check.md): groupProgress p95 under 300 ms on the largest seeded group, and every MCP read tool p95 under 1 s. It runs in the official k6 image, `grafana/k6:2.3.0`, pinned by digest in `run.sh`. Nothing is added to the app. The results go in [docs/launch-check.md](../../docs/launch-check.md).

## What it does

1. **Setup, as a real client.** It gets the MCP token the way Claude does: it reads the discovery documents, registers a client (`POST /oauth/register`), opens the authorize URL, logs in on the web login page, approves the consent page and exchanges the code with its PKCE verifier. Then `initialize` and `tools/list` on `/mcp`. It gets the API token with `POST /api/v1/auth/login`. It finds the group by name, and the group's learner who is in the most of the educator's groups.
2. **groupProgress:** the full GraphQL query from the contract, 200 times, 10 at once.
3. **MCP read tools:** each of the five tools 100 times, in turn, one call at a time, and at most one call every 1.2 seconds (50 a minute).

It reports p50, p95, p99 and max per operation, and writes the raw k6 summary to a JSON file. The time is k6's `http_req_duration`: from sending the request to the last byte of the answer. Connection setup (DNS, TCP, TLS) is not in it.

## Rate limits and run time

A run takes about 12 minutes. The limits stay on:

- REST and GraphQL allow 300 requests a minute per user. groupProgress makes 200, plus 4 setup calls, so it runs at full speed with 10 at once and stays under the limit. It has 90 seconds.
- `/mcp` allows 60 calls a minute per educator. The 500 tool calls start 90 seconds after setup's 2 calls. Each call starts at least 1.2 seconds after the one before, so they take at least 10 minutes and no minute holds more than 51.
- Client registration allows 10 an hour per IP address, so at most 10 runs an hour from one address.

Do not run two at once, for example the laptop and the EC2 run: both use the same educator's limits.

## Pass or fail

Every target and count is a k6 threshold. Exit code 0: every request answered 2xx, every check passed, every operation ran its full count and met its target. Exit code 99: a threshold failed, so a target was missed, a count is short, or a request got a non-2xx answer or a 429. The report lists the failed thresholds. Any other code: the run stopped early, often in setup, and the log says why.

## Which group and educator

The largest seeded group is **Pediatrics Group B**: 50 learners and 12 assignments, 511 learner-assignment pairs, the most of any seeded group. Cardiology Group C has more learners (58) but 8 assignments, 361 pairs. The pairs decide the size of groupProgress (one status per pair). Both groups are Hannah Clarke's, so the run logs in as `hannah.clarke@example.edu`. Anna Keller, the demo educator in the README, does not teach either.

The seed is fixed, so every seeded database has the same groups.

## Settings

| Variable | Required | Meaning |
|---|---|---|
| `BASE_URL` | yes | The app's public URL, the same as its `APP_URL`: `https://<domain>`, or `http://localhost:8080` locally. The run stops if the discovery documents name another URL |
| `EDUCATOR_EMAIL`, `EDUCATOR_PASSWORD` | yes | An educator who teaches the group. Seed data: `hannah.clarke@example.edu`, `password` |
| `GROUP_NAME` | no | Default `Pediatrics Group B` |
| `RESULTS_DIR` | no | Where `run.sh` saves the files. Default `scripts/load/results` |
| `K6_NETWORK` | no | The Docker network of the k6 container. Default `bridge` |

## Run it

Needs Docker and a seeded database. Each run saves three files named after the label and the start time: `<label>-<time>.json` (the raw k6 summary), `.txt` (the report) and `.log` (the console, with the start and finish times). A failed request writes its URL to the log: replace the domain with `<domain>` before you commit a file that holds it.

**Locally,** against the compose app (`docker compose up -d`, then `php artisan migrate:fresh --seed`). The discovery documents and redirects name `http://localhost:8080`, so the k6 container joins the app container's network, where that address is the app:

```
BASE_URL=http://localhost:8080 EDUCATOR_EMAIL=hannah.clarke@example.edu EDUCATOR_PASSWORD=password \
  K6_NETWORK="container:$(docker compose ps -q app)" scripts/load/run.sh local
```

**From a laptop,** against AWS:

```
BASE_URL=https://<domain> EDUCATOR_EMAIL=hannah.clarke@example.edu EDUCATOR_PASSWORD=password \
  scripts/load/run.sh laptop
```

**From eu-central-1,** the same command with the label `eu-central-1`, on an EC2 instance in `eu-central-1` (Amazon Linux 2023):

```
sudo dnf install -y docker git
sudo systemctl start docker
sudo usermod -aG docker "$USER"     # then log out and in again
git clone https://github.com/GilbertChian8/learntrack.git && cd learntrack
```

CloudShell in `eu-central-1` works the same way if `docker version` answers there.

## What a run leaves behind

One OAuth client named `LearnTrack load check` with its tokens, and one API token. They do no harm: the tokens expire (1 hour and 8 hours), and the daily housekeeping run deletes expired tokens. The client stays.
