# WHCSystem Modules

![WHCSystem](https://img.shields.io/badge/WHCSystem-Official%20Modules-2563EB)
![PHP](https://img.shields.io/badge/Language-PHP-777BB4?logo=php&logoColor=white)
![WHMCS](https://img.shields.io/badge/Integration-WHMCS-0099CC)
![WISECP](https://img.shields.io/badge/Integration-WISECP-2563EB)
![API](https://img.shields.io/badge/API-REST-16A34A)
![GitHub release](https://img.shields.io/github/v/release/hamzadenizyilmaz/WHCSystem-Module)

Official WHMCS and WISECP modules for WHCSystem.

These modules integrate WHMCS and WISECP with WHCSystem, allowing hosting providers to automate virtual server provisioning and manage services directly from their billing platform.

[Website](https://whcsystem.com/) · [Releases](https://github.com/hamzadenizyilmaz/WHCSystem-Module/releases) · [Issues](https://github.com/hamzadenizyilmaz/WHCSystem-Module/issues)

## Overview

WHCSystem provides a unified management platform for Proxmox VE and VMware infrastructure. The modules communicate directly with WHCSystemAPI, without requiring direct database or hypervisor access.

| Platform | Module | Language |
| :--- | :--- | :--- |
| WHMCS | `whmcs/` | PHP |
| WISECP | `wisecp/` | PHP |

## Features

- Automated virtual server provisioning
- Service activation, suspension and termination
- Server start, stop and reboot
- Virtual machine and resource information
- IP address and network management
- Template and product policy integration
- Service operation history
- API-based authentication and authorization

Available operations may vary depending on the virtualization provider and installed WHCSystem version.

## Repository Structure

```text
WHCSystem-Module/
├── whmcs/
├── wisecp/
└── README.md
```

## Installation

Download the appropriate module from the [Releases](https://github.com/hamzadenizyilmaz/WHCSystem-Module/releases) page or the WHCSystem customer panel.

### WHMCS

1. Extract the WHMCS module package.
2. Upload the module to your WHMCS server module directory.
3. Configure the WHCSystem server connection.
4. Assign the module to the relevant product.
5. Configure the provisioning policy and test the connection.

### WISECP

1. Extract the WISECP module package.
2. Upload the module to your WISECP module directory.
3. Configure the WHCSystem server connection.
4. Assign the module to the relevant service.
5. Configure the provisioning policy and test the connection.

## Requirements

| Component | Requirement |
| :--- | :--- |
| WHCSystem | Active installation and valid license |
| Billing Platform | WHMCS or WISECP |
| PHP | Compatible runtime with cURL and JSON support |
| Connection | HTTPS |
| Authentication | Authorized WHCSystem API credentials |
| Virtualization | Proxmox VE or VMware |

Version-specific requirements are provided in the release notes.

## Updates

Module updates are distributed through GitHub Releases and the WHCSystem platform.

Before installing an update, review the release notes, verify API compatibility and back up your existing module files.

## Support

For technical issues and bug reports, use the [GitHub Issues](https://github.com/hamzadenizyilmaz/WHCSystem-Module/issues) page.

Include your module version, WHCSystem version, billing platform, PHP version and the relevant error details.

Do not include passwords, API credentials, license keys or customer information in public issues.

## License

Use of these modules is subject to the applicable repository license terms. A valid WHCSystem license is required to access the WHCSystem platform.

WHMCS, WISECP, Proxmox and VMware are trademarks of their respective owners.

---

Copyright © 2026 WHCSystem.

[whcsystem.com](https://whcsystem.com/)
