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

The example below assumes Blesta was installed using the standard installation script and is located at `/home/blesta/public_html`. If you installed Blesta elsewhere, adjust the paths accordingly.

### 1. Install the module

Clone the repository and move the `epp` module into Blesta:

```bash
cd /tmp
git clone --depth 1 https://github.com/getnamingo/blesta-epp-registrar.git
mv blesta-epp-registrar/epp /home/blesta/public_html/components/modules/
chown -R blesta:blesta /home/blesta/public_html/components/modules/epp
```

The main module file should now be located at `/home/blesta/public_html/components/modules/epp/epp.php`

### 2. Install the EPP client certificate

Most production EPP registries require a client certificate and private key issued or approved by the registry.

By default, the module expects:

```bash
/home/blesta/public_html/components/modules/epp/cert.pem
/home/blesta/public_html/components/modules/epp/key.pem
```

If the registry supplied these files, copy them into the module directory:

```bash
cp /path/to/cert.pem /home/blesta/public_html/components/modules/epp/cert.pem
cp /path/to/key.pem /home/blesta/public_html/components/modules/epp/key.pem

chown blesta:blesta /home/blesta/public_html/components/modules/epp/cert.pem
chown blesta:blesta /home/blesta/public_html/components/modules/epp/key.pem

chmod 644 /home/blesta/public_html/components/modules/epp/cert.pem
chmod 600 /home/blesta/public_html/components/modules/epp/key.pem
```

Absolute certificate and private-key paths may also be used in the EPP account configuration.

### 3. Generate a certificate for testing only

If you are using a test EPP server that accepts self-signed client certificates, you can generate a temporary certificate:

```bash
cd /home/blesta/public_html/components/modules/epp

openssl genrsa -out key.pem 2048

openssl req -new -x509 \
    -key key.pem \
    -out cert.pem \
    -days 365

chown blesta:blesta key.pem cert.pem
chmod 600 key.pem
chmod 644 cert.pem
```

Do not normally use a self-signed certificate in production. Use the certificate and private key issued or approved by your registry.

### 4. Install the module in Blesta

In Blesta, go to **Packages → Domain Options → Registrars**.

Select EPP Registrar, install it, and add your EPP account.

Configure the registry hostname, EPP port, Client ID, password, certificate/key paths, registry profile, and supported TLDs.

For the default certificate locations, use:

```text`
cert.pem
key.pem
```

or use absolute paths if preferred.

### 5. Configure domain packages

In Blesta, go to **Packages → Domain Options**.

Import or add the TLDs you want to sell, then configure pricing and registrar assignment for each TLD.

For each TLD:

1. Select **EPP Registrar** as the registrar.
2. Choose the appropriate EPP account/module row.
3. Configure the registration, renewal, transfer, and redemption pricing as required.
4. Set the supported registration periods.
5. Configure the default nameservers that should be used for new registrations.

For example:

```text
ns1.example.com
ns2.example.com
```

Make sure the TLD is also listed under **Supported TLDs** in the corresponding EPP account configuration.

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