# Porthole

Pull activity reports from a [Harbor](https://goharbor.io) container registry, exported as CSV.

## Installation

### Pre-built binary (no PHP required)

```bash
docker build -t porthole -f prod/Dockerfile .
docker create --name porthole-extract porthole
docker cp porthole-extract:/porthole ./porthole
docker rm porthole-extract
```

### From source

```bash
docker build -t porthole-dev -f dev/Dockerfile .
docker run --rm -v $(pwd):/app porthole-dev /usr/bin/composer install
```

## Usage

```
bin/porthole report [options]
```

| Option | Description |
|---|---|
| `--harbor-url` | Harbor registry base URL (required) |
| `--harbor-token` | API token — falls back to `$HARBOR_TOKEN` |
| `--harbor-username` | Username for Basic auth — falls back to `$HARBOR_USERNAME` |
| `--mode` | `images` (default) or `users` |
| `--from` | Start date `YYYY-MM-DD` (optional) |
| `--to` | End date `YYYY-MM-DD` (optional) |
| `--output` | Output CSV file path (required) |
| `--no-verify-ssl` | Disable SSL verification (self-signed certs) |

### Authentication

Bearer token (robot account):

```bash
export HARBOR_TOKEN=your-token
```

Basic auth (username + token as password):

```bash
export HARBOR_USERNAME=robot\$myaccount
export HARBOR_TOKEN=your-token
```

## Examples

```bash
# Images report — all time
bin/porthole report \
  --harbor-url=https://registry.example.com \
  --output=report.csv

# Images report — filtered by date range
bin/porthole report \
  --harbor-url=https://registry.example.com \
  --from=2025-01-01 --to=2025-06-30 \
  --output=report.csv

# Users report
bin/porthole report \
  --harbor-url=https://registry.example.com \
  --mode=users \
  --output=report.csv
```

## Output format

**images** mode — sorted by pull count descending:

```
Image;Tag;Number of pulls
library/nginx;latest;142
library/redis;7;38
```

**users** mode — sorted by username ascending, then pull count descending:

```
User;Image;Tag;Number of pulls
alice;library/nginx;latest;97
bob;library/redis;7;12
```

## Development

```bash
make build    # build the dev Docker image
make install  # composer install
make test     # PHPUnit
make cs       # PHP CS Fixer
make stan     # PHPStan
```
