# Persoc coverage

Persoc ships a lightweight Xdebug-based coverage runner for the PHP source tree.

Run it from the repository root:

```sh
./coverage.sh
```

The script requires Xdebug coverage support for CLI PHP. On Debian/Ubuntu systems this usually means installing `php-xdebug` and making sure CLI PHP can load it.

Generated files are written under `.coverage/`:

- `.coverage/coverage.txt` for the text summary;
- `.coverage/coverage.json` for machine-readable totals;
- `.coverage/html/index.html` for the HTML report.

The coverage runner executes all `tests/*.php` files except the shared helpers and the coverage tools themselves. It only counts files under `src/`.

Coverage is a maintenance aid, not a production dependency. The Debian package does not depend on Xdebug.
