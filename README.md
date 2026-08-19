# Blesta EPP Registrar

[![StandWithUkraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

[![SWUbanner](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/banner2-direct.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

A generic Blesta registrar module for connecting to any domain registry that uses the EPP protocol.

## Features

- Domain availability, registration, transfer, renewal, restore, and explicit registry deletion
- Registration/expiration-date synchronization and live EPP status display
- Nameserver management and child nameserver (host object) management
- Registrant, admin, technical, and billing contact management
- Registrar lock and `clientHold` management
- AuthInfo retrieval and update
- Contact privacy/disclosure management
- DNSSEC DS record add/remove/list
- Transfer query, approve, cancel, and reject actions
- IDN conversion
- EPP fee-extension premium detection
- TMCH claims-create fields
- Generic and registry-specific profiles supplied by the Namingo client

## Registry Support

| Registry | TLDs | Profile | Needs |
|----------|----------|----------|----------|
| Generic RFC EPP | any | | |
| AFNIC | .fr/others | FR | |
| CARNET | .hr | HR | |
| Caucasus Online | .ge | GE | |
| CentralNic | all | | Set AuthInfo on Request / Min Data Set and gTLD Enabled (for gTLD) |
| CoCCA | all | | Set AuthInfo on Request / Min Data Set and gTLD Enabled (for gTLD) |
| CORE/Knipp | all | | Min Data Set and gTLD Enabled (for gTLD) |
| Domicilium | .im | | |
| DRS.UA | all | | | |
| EURid | .eu | EU | |
| GoDaddy Registry | all | | Min Data Set and gTLD Enabled (for gTLD) |
| Google Nomulus | all | | Min Data Set and gTLD Enabled (for gTLD) |
| Hello Registry | all | | Min Data Set and gTLD Enabled (for gTLD) |
| Hostmaster | .ua | UA | |
| Identity Digital | all | | Min Data Set and gTLD Enabled (for gTLD) |
| IIS | .se, .nu | SE | |
| IT.COM | all | | |
| Namingo | all | | |
| NASK | .pl | PL | |
| NIC Chile | .cl | | |
| NIC Mexico | .mx | MX | |
| NIC.LV | .lv | LV | |
| .PT | .pt | PT | |
| Regtons | all | | |
| RoTLD | .ro | | |
| RyCE | all | | |
| SIDN | all | | |
| SWITCH | .ch, .li | SWITCH | Set AuthInfo on Request |
| Tucows Registry | all | | Min Data Set and gTLD Enabled (for gTLD) |
| Verisign | all | VRSN | Min Data Set and gTLD Enabled (for gTLD) |
| ZADNA | .za | | |
| ZDNS | all | | |

### In Progress

| Registry | TLDs | Profile | Status |
|----------|----------|----------|----------|
| DENIC | .de | DE | |
| DOMREG | .lt | LT | |
| FORTH-ICS | .gr, .ελ | GR | |
| FRED | .cz/any | FRED | |
| NORID | .no | NO | |

### Paid Registry Support

| Registry | TLDs | Profile | Status |
|----------|----------|----------|----------|
| HKIRC | .hk | HK | |
| Internet.ee | .ee | EE | |
| Registro.it | .it | IT | |
| Traficom | .fr | FI | |

## Requirements

- Blesta 5.9 or newer (including Blesta 6)
- PHP 8.3 or newer with OpenSSL, SimpleXML, and XMLWriter
- Outbound TCP access to the registry's EPP port
- A registry-issued/approved client certificate and private key

## Installation

1. Extract this archive into Blesta so the main file is:

   ```text
   /path/to/blesta/components/modules/epp/epp.php
   ```

2. Put the client certificate and private key in the module directory (or use absolute paths). The default relative paths are:

   ```text
   components/modules/epp/cert.pem
   components/modules/epp/key.pem
   ```

3. Make the files readable by the PHP/web-server user. Keep the private key out of web-accessible backups and restrict its permissions.

4. In Blesta, go to **Packages → Domain Options → Registrars** (or **Settings → Company → Modules** on older installations), install **EPP Registrar**, then add an EPP account.

5. Enter the same endpoint, TLS, authentication, registry-profile, Minimum Data Set, gTLD, fee-extension, and logging values. Also enter the TLDs served by this account; Blesta needs this list to expose the registrar for those TLDs.

6. Create/assign domain packages through Blesta's Domain Manager and select the desired TLDs and default nameservers.

## Troubleshooting

1. **Network access / allowlisting**
   - Ensure the server’s outbound IP(s) are allowlisted by the registry EPP endpoint (both **IPv4** and **IPv6**, if applicable).

2. **IPv6 considerations**
   - Confirm both sides support IPv6 if you intend to use it.
   - If you encounter IPv6-related connection issues, temporarily **disable IPv6** on the client side and retry.
   
3. **EPP server access**
   - If you are unsure whether your server can reach the EPP endpoint, test the connection using OpenSSL:

   Basic test:
   ```bash
   openssl s_client -connect epp.example.com:700
   ```

   Test with client certificate:
   ```bash
   openssl s_client -connect epp.example.com:700 -CAfile cacert.pem -cert cert.pem -key key.pem
   ```
   
   Replace the hostname and certificate paths as needed. These tests help diagnose network or TLS issues.
   
4. **Generating a client TLS certificate (testing only)**
   - If you do not yet have a client certificate for EPP access, you can generate a temporary self-signed pair:

   ```bash
   openssl genrsa -out key.pem 2048
   openssl req -new -x509 -key key.pem -out cert.pem -days 365
   ```
   
   For production, use a certificate issued or approved by the registry (not a self-signed certificate).

5. **Registrar prefix**
   - Ensure the module is configured with the correct **registrar prefix** for your registry connection.

6. **Transfer AuthInfo not returned by registry**
   - Some registries (e.g. **CentralNic**, **CoCCA**) may not return the transfer AuthInfo code via standard `domain:info`.
   - If your module does not display the transfer code, enable the option **“Set AuthInfo on Request”** in the module configuration. This forces the module to set/generate AuthInfo when requested, so it can be displayed/managed consistently.

### Need More Help?

If the steps above don’t resolve your issue, refer to the Blesta logs to identify the specific problem.

## Support

Your feedback and inquiries are invaluable to Namingo's evolutionary journey. If you need support, have questions, or want to contribute your thoughts:

- **Email**: Feel free to reach out directly at [help@namingo.org](mailto:help@namingo.org).

- **Discord**: Or chat with us on our [Discord](https://discord.gg/97R9VCrWgc) channel.
  
- **GitHub Issues**: For bug reports or feature requests, please use the [Issues](https://github.com/getnamingo/blesta-epp-registrar/issues) section of our GitHub repository.

We appreciate your involvement and patience as Namingo continues to grow and adapt.

## Support This Project

If you find Blesta EPP Registrar useful, consider donating:

- [Donate via Stripe](https://donate.stripe.com/7sI2aI4jV3Offn28ww)
- BTC: `bc1q9jhxjlnzv0x4wzxfp8xzc6w289ewggtds54uqa`
- ETH: `0x330c1b148368EE4B8756B176f1766d52132f0Ea8`

## Licensing

Blesta EPP Registrar is licensed under the MIT License.