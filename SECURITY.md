# Security policy

Report suspected vulnerabilities privately through the contact channel published at [wppilot.co](https://wppilot.co). Do not include production credentials, private keys, customer data, or exploit traffic against systems you do not own.

## Deployment guidance

- Keep Production Safe selected unless broader access is intentional.
- Prefer OAuth over credentials embedded in client configuration.
- Use HTTPS for remotely reachable sites and revoke connected apps that are no longer needed.
- Grant AI access only to a WordPress account with the minimum capabilities required.
- Maintain tested backups before enabling mutation workflows.
- Review the WPPilot change ledger and WordPress/WooCommerce logs after consequential operations.
- Treat Developer Full Access as privileged server administration access.

WPPilot's diagnostics inspect selected WordPress and PHP configuration. They are not a malware scanner, penetration test, compliance certification, accounting audit, or availability monitor.
