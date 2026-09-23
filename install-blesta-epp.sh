#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

VERSION='1.0.2'
# Pin installer input to the reviewed source commit so a moved tag or branch
# cannot silently change what is installed.
SOURCE_COMMIT='1fd6ad912cbe4aae6a4b39d2ed4a37d0f616d5bc'
ARCHIVE="blesta-epp-registrar-${VERSION}.tar.gz"
DOWNLOAD_URL="https://github.com/getnamingo/blesta-epp-registrar/archive/${SOURCE_COMMIT}.tar.gz"

CC_REGISTRIES=(
  registrebf switch niccl cocca cocca2 eurid afnic nicge carnet nicim switchli
  niclv nicmx sidn iisnu nask rotld iis hostmaster ye zadna
)
G_REGISTRIES=(
  central core dns godaddy google hello identity org itcom namingo regtons ryce
  tucows verisign zdns
)
R_REGISTRIES=(drsua ukrnames)

usage() {
  cat <<EOF_USAGE
Usage:
  $(basename "$0") <registry> [blesta_path]

Examples:
  $(basename "$0") namingo
  $(basename "$0") namingo /home/blesta/public_html

Supported registry profiles:
  cc: $(printf '%s ' "${CC_REGISTRIES[@]}")
  g:  $(printf '%s ' "${G_REGISTRIES[@]}")
  r:  $(printf '%s ' "${R_REGISTRIES[@]}")
EOF_USAGE
}

die() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

info() {
  printf '%s\n' "$*"
}

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "Required command not found: $1"
}

is_supported_registry() {
  local wanted=$1 item
  for item in "${CC_REGISTRIES[@]}" "${G_REGISTRIES[@]}" "${R_REGISTRIES[@]}"; do
    [[ "$item" == "$wanted" ]] && return 0
  done
  return 1
}

is_blesta_root() {
  local path=${1%/}
  [[ -f "$path/index.php" && -d "$path/components/modules" ]]
}

find_blesta_root() {
  local found=() modules root item seen base

  for base in /home /var/www; do
    [[ -d "$base" ]] || continue

    while IFS= read -r modules; do
      root=${modules%/components/modules}
      is_blesta_root "$root" || continue

      seen=0
      for item in "${found[@]:-}"; do
        [[ "$item" == "$root" ]] && seen=1 && break
      done
      ((seen == 0)) && found+=("$root")
    done < <(find "$base" -maxdepth 7 -type d -path '*/components/modules' -print 2>/dev/null || true)
  done

  if (("${#found[@]}" == 1)); then
    printf '%s\n' "${found[0]}"
    return 0
  fi

  return 1
}

prompt_blesta_root() {
  local path
  while true; do
    if [[ -t 0 || -t 1 || -t 2 ]]; then
      read -r -p 'Blesta path: ' path </dev/tty
    else
      die 'Blesta was not uniquely detected and no interactive terminal is available.'
    fi

    path=${path%/}
    if is_blesta_root "$path"; then
      printf '%s\n' "$path"
      return 0
    fi

    printf 'Not a valid Blesta root: %s (expected index.php and components/modules)\n' "$path" >&2
  done
}

ask_yes_no() {
  local prompt=$1 answer

  [[ -t 0 || -t 1 || -t 2 ]] || return 1

  while true; do
    read -r -p "$prompt [y/N]: " answer </dev/tty
    case "${answer,,}" in
      y|yes) return 0 ;;
      ''|n|no) return 1 ;;
      *) printf 'Please answer yes or no.\n' >&2 ;;
    esac
  done
}

if (($# == 0)); then
  usage
  exit 0
fi

if [[ "${1:-}" == '-h' || "${1:-}" == '--help' ]]; then
  usage
  exit 0
fi

(($# <= 2)) || die 'Too many arguments.'

registry=${1,,}
is_supported_registry "$registry" || {
  usage >&2
  die "Unsupported registry profile: $registry"
}

# Equivalent to PHP ucfirst() after normalizing the module slug to lowercase.
registry_name="${registry^}"

need_cmd curl
need_cmd tar
need_cmd grep
need_cmd find
need_cmd perl
need_cmd mktemp
need_cmd stat

if [[ ${EUID} -eq 0 ]]; then
  SUDO=()
else
  need_cmd sudo
  SUDO=(sudo)
fi

if (($# == 2)); then
  blesta_path=${2%/}
  is_blesta_root "$blesta_path" || die "Invalid Blesta path: $blesta_path"
else
  if blesta_path=$(find_blesta_root); then
    info "Detected Blesta: $blesta_path"
  else
    info 'Blesta was not uniquely detected under /home or /var/www.'
    blesta_path=$(prompt_blesta_root)
  fi
fi

modules_dir="$blesta_path/components/modules"
module_dest="$modules_dir/$registry"
[[ ! -L "$module_dest" ]] || die "Refusing to replace symlinked module directory: $module_dest"

owner_uid=$(stat -c '%u' "$modules_dir")
owner_gid=$(stat -c '%g' "$modules_dir")

workdir=$(mktemp -d -t namingo-blesta-epp.XXXXXXXX)
cleanup() {
  rm -rf -- "$workdir"
}
trap cleanup EXIT INT TERM

archive_path="$workdir/$ARCHIVE"
extract_dir="$workdir/extracted"
mkdir -p "$extract_dir"

info "Downloading Namingo Blesta EPP module v${VERSION}..."
curl --fail --location --silent --show-error \
  --retry 3 --retry-delay 1 --retry-all-errors \
  --proto '=https' --tlsv1.2 \
  --output "$archive_path" "$DOWNLOAD_URL"

# Reject path traversal before extraction.
if tar -tzf "$archive_path" | grep -Eq '(^/|(^|/)\.\.(/|$))'; then
  die 'Archive contains an unsafe path.'
fi

tar -xzf "$archive_path" -C "$extract_dir"

mapfile -t module_candidates < <(
  find "$extract_dir" -type f -path '*/epp/epp.php' -printf '%h\n' | sort -u
)
(("${#module_candidates[@]}" == 1)) || die 'Unexpected archive structure: could not uniquely locate epp/epp.php.'
module_dir=${module_candidates[0]}

[[ -d "$module_dir/lib" ]] || die 'Unexpected archive structure: missing lib/ directory.'
[[ -d "$module_dir/views" ]] || die 'Unexpected archive structure: missing views/ directory.'
[[ -f "$module_dir/epp.php" ]] || die 'Unexpected archive structure: missing epp.php.'
[[ -f "$module_dir/config/epp.php" ]] || die 'Unexpected archive structure: missing config/epp.php.'
[[ -f "$module_dir/language/en_us/epp.php" ]] || die 'Unexpected archive structure: missing language/en_us/epp.php.'

# Customize only Blesta module identity. Never rewrite bundled EPP library code.
customizer="$workdir/customize-blesta.pl"
cat > "$customizer" <<'PERL'
use strict;
use warnings;

my $registry      = $ENV{'REGISTRY'}      // die "REGISTRY missing\n";
my $registry_name = $ENV{'REGISTRY_NAME'} // die "REGISTRY_NAME missing\n";

for my $path (@ARGV) {
    open my $in, '<', $path or die "Cannot read $path: $!\n";
    local $/;
    my $content = <$in>;
    close $in;

    # Blesta runtime identity.
    $content =~ s/\bclass Epp extends RegistrarModule\b/class ${registry_name} extends RegistrarModule/g;
    $content =~ s/private const MODULE_DIR = 'epp';/private const MODULE_DIR = '${registry}';/g;
    $content =~ s/Language::loadLang\('epp'/Language::loadLang('${registry}'/g;
    $content =~ s/Configure::load\('epp'/Configure::load('${registry}'/g;

    # Language and Configure namespace used by config.json, PHP and PDT views.
    $content =~ s/Epp\./${registry_name}./g;

    # Give each generated module a distinct name in the Blesta UI.
    if ($path =~ m{/language/}) {
        my $old_name = "\$lang['${registry_name}.name'] = 'EPP Registrar';";
        my $new_name = "\$lang['${registry_name}.name'] = '${registry_name} EPP Registrar';";
        $content =~ s/\Q$old_name\E/$new_name/g;

        my $old_description = "\$lang['${registry_name}.description'] = 'Connect Blesta to a domain registry through the standard EPP protocol.';";
        my $new_description = "\$lang['${registry_name}.description'] = 'Blesta EPP integration for the ${registry_name} registry.';";
        $content =~ s/\Q$old_description\E/$new_description/g;
    }

    open my $out, '>', $path or die "Cannot write $path: $!\n";
    print {$out} $content;
    close $out or die "Cannot close $path: $!\n";
}
PERL

mapfile -d '' customizable_files < <(
  find "$module_dir" -type f \
    \( -name '*.php' -o -name '*.pdt' -o -name '*.json' \) \
    ! -path "$module_dir/lib/*" -print0
)
(("${#customizable_files[@]}" > 0)) || die 'Unexpected archive structure: no customizable module files found.'
REGISTRY="$registry" REGISTRY_NAME="$registry_name" perl "$customizer" "${customizable_files[@]}"

# Blesta expects the main module, config file and language file to match
# the module directory/class slug.
mv -- "$module_dir/epp.php" "$module_dir/${registry}.php"
mv -- "$module_dir/config/epp.php" "$module_dir/config/${registry}.php"

while IFS= read -r -d '' lang_file; do
  mv -- "$lang_file" "$(dirname "$lang_file")/${registry}.php"
done < <(find "$module_dir/language" -mindepth 2 -maxdepth 2 -type f -name 'epp.php' -print0)

main_file="$module_dir/${registry}.php"
config_file="$module_dir/config/${registry}.php"
lang_file="$module_dir/language/en_us/${registry}.php"

[[ -f "$main_file" ]] || die 'Failed to rename the main Blesta module file.'
[[ -f "$config_file" ]] || die 'Failed to rename the Blesta module config file.'
[[ -f "$lang_file" ]] || die 'Failed to rename the Blesta language file.'

grep -Fq "class ${registry_name} extends RegistrarModule" "$main_file" \
  || die 'Failed to customize the Blesta module class.'
grep -Fq "private const MODULE_DIR = '${registry}'" "$main_file" \
  || die 'Failed to customize the Blesta module directory identity.'
grep -Fq "Language::loadLang('${registry}'" "$main_file" \
  || die 'Failed to customize the Blesta language loader.'
grep -Fq "Configure::load('${registry}'" "$main_file" \
  || die 'Failed to customize the Blesta config loader.'
grep -Fq "\"name\": \"${registry_name}.name\"" "$module_dir/config.json" \
  || die 'Failed to customize config.json language namespace.'

if grep -R -Fq --exclude-dir=lib 'Epp.' "$module_dir"; then
  die 'Customization left an Epp.* runtime namespace outside lib/.'
fi

stage_dest="$modules_dir/.${registry}.install.$$"
backup_dest="$modules_dir/.${registry}.backup.$$"
"${SUDO[@]}" rm -rf -- "$stage_dest" "$backup_dest"
"${SUDO[@]}" mkdir -p -- "$stage_dest"
"${SUDO[@]}" cp -a -- "$module_dir/." "$stage_dest/"

# Conservative production permissions.
"${SUDO[@]}" find "$stage_dest" -type d -exec chmod 0755 {} +
"${SUDO[@]}" find "$stage_dest" -type f -exec chmod 0644 {} +

# Preserve existing client certificates and keys during upgrades.
if [[ -d "$module_dest" ]]; then
  while IFS= read -r -d '' pem; do
    "${SUDO[@]}" cp -p -- "$pem" "$stage_dest/"
  done < <("${SUDO[@]}" find "$module_dest" -maxdepth 1 -type f -name '*.pem' -print0)
fi

"${SUDO[@]}" chown -R "${owner_uid}:${owner_gid}" -- "$stage_dest"
"${SUDO[@]}" find "$stage_dest" -maxdepth 1 -type f -name '*.pem' -exec chmod 0600 {} +

if [[ -e "$module_dest" ]]; then
  "${SUDO[@]}" mv -- "$module_dest" "$backup_dest"
  if ! "${SUDO[@]}" mv -- "$stage_dest" "$module_dest"; then
    "${SUDO[@]}" mv -- "$backup_dest" "$module_dest" || true
    die 'Failed to install customized module; previous module was restored.'
  fi
  "${SUDO[@]}" rm -rf -- "$backup_dest"
  info "Upgraded Blesta registrar module: $registry"
else
  "${SUDO[@]}" mv -- "$stage_dest" "$module_dest"
  info "Installed Blesta registrar module: $registry"
fi

cert_path="$module_dest/cert.pem"
key_path="$module_dest/key.pem"
cert_generated='no'

if ask_yes_no "Generate a self-signed TEST EPP certificate for ${registry_name}?"; then
  need_cmd openssl

  if [[ -e "$cert_path" || -e "$key_path" ]]; then
    die "Refusing to overwrite an existing certificate/key: $cert_path or $key_path"
  fi

  cert_tmp="$workdir/cert.pem"
  key_tmp="$workdir/key.pem"

  openssl genrsa -out "$key_tmp" 2048 >/dev/null 2>&1
  openssl req -new -x509 \
    -key "$key_tmp" \
    -out "$cert_tmp" \
    -days 365 \
    -sha256 \
    -subj "/C=XX/ST=Test/L=Test/O=Namingo Test/OU=EPP/CN=${registry}.test/emailAddress=test@example.invalid" \
    >/dev/null 2>&1

  "${SUDO[@]}" install -o "$owner_uid" -g "$owner_gid" -m 0600 -- "$cert_tmp" "$cert_path"
  "${SUDO[@]}" install -o "$owner_uid" -g "$owner_gid" -m 0600 -- "$key_tmp" "$key_path"
  cert_generated='yes'
fi

cat <<EOF_DONE

Installation complete.
Registry module: ${registry_name}
Blesta path: ${blesta_path}
Module directory: ${module_dest}
Main module file: ${module_dest}/${registry}.php
Config file: ${module_dest}/config/${registry}.php
Language file: ${module_dest}/language/en_us/${registry}.php
EOF_DONE

if [[ "$cert_generated" == 'yes' ]]; then
  cat <<EOF_CERT
Test certificate: ${cert_path}
Test private key: ${key_path}

These are self-signed TEST credentials only. Replace them with the certificate/key accepted by the registry before production EPP use.
EOF_CERT
else
  info 'Test certificate: not generated.'
fi
