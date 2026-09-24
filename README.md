# Blesta EPP Registrar

[![StandWithUkraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

[![SWUbanner](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/banner2-direct.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

A generic Blesta registrar module for connecting to any domain registry that uses the EPP protocol.

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

- Blesta 6.0 or newer
- PHP 8.2 or newer with OpenSSL, SimpleXML, and XMLWriter
- Outbound TCP access to the registry's EPP port
- A registry-issued/approved client certificate and private key

## Installation

The recommended way to install the module is with the automated installer:

```bash
bash <(wget -qO- https://namingo.org/install-blesta-epp.sh) namingo
```

Replace `namingo` with the registry name. Run without parameters to see all supported registry profiles:

```bash
bash <(wget -qO- https://namingo.org/install-blesta-epp.sh)
```

The installer automatically looks for Blesta under `/home` and `/var/www`. You may also specify the Blesta path explicitly:

```bash
bash <(wget -qO- https://namingo.org/install-blesta-epp.sh) namingo /home/blesta/public_html
```

The installer creates a registry-specific Blesta module so multiple EPP modules can coexist without class, configuration, or language-name collisions. For example, installing `namingo` creates:

```text
components/modules/namingo/
components/modules/namingo/namingo.php
components/modules/namingo/config/namingo.php
components/modules/namingo/language/en_us/namingo.php
```

It also changes the Blesta module class and the internal language/configuration namespace from the generic `Epp` name to the selected registry name. The bundled EPP client under `lib/` is left unchanged.

During installation, the script can optionally generate a **self-signed EPP client certificate for testing**. The generated files are:

```text
components/modules/<registry>/cert.pem
components/modules/<registry>/key.pem
```

For production, replace them with the certificate and private key issued or approved by your registry.

After installation, in Blesta go to **Packages → Domain Options → Registrars**, select the newly created registry module (for example **Namingo EPP Registrar**), install it, and add your EPP account.

Configure the registry hostname, EPP port, Client ID, password, certificate/key paths, registry profile, supported TLDs, and any registry-specific options. With the default certificate locations, use:

```text
cert.pem
key.pem
```

Then go to **Packages → Domain Options**, import or add the TLDs you want to sell, configure their pricing, and assign them to the installed registrar module.

### Manual installation

If you do not want to use the installer, clone the repository and copy the generic `epp` module into Blesta:

```bash
cd /tmp
git clone --depth 1 https://github.com/getnamingo/blesta-epp-registrar.git
cp -a blesta-epp-registrar/epp /home/blesta/public_html/components/modules/
```

The generic module can be used directly as `epp`. If you need more than one EPP registrar module in the same Blesta installation, use the automated installer so each copy receives a unique module directory, class, config namespace, and language namespace.

## Upgrade

Upgrade a registry-specific module by running the same installer again:

```bash
bash <(wget -qO- https://namingo.org/install-blesta-epp.sh) namingo /home/blesta/public_html
```

Existing top-level `*.pem` certificate/key files in that registry module directory are preserved during the upgrade.

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