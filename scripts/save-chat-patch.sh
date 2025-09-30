                                    OK,LKLOI7UFVYTGH CVB #0!/usr/bin/env bash
                                    "M NB"
set -euo pipefail
root="$(pwd)"
tmp="$(mktemp)"
cat - > "$tmp"
current_file=""
buffer=""
flush_file() {
  if [[ -n "${current_file}" ]]; then
    out="$root/$current_file"
    mkdir -p "$(dirname "$out")"
    printf "%s" "$buffer" | sed -e 's/\r$//' -e '$a\' > "$out"
    echo "Wrote: $current_file"
    current_file=""
    buffer=""
  fi
}
while IFS= read -r line || [[ -n "$line" ]]; do
  if [[ "$line" =~ ^\*\*\*\ Add\ File:\ (.+)$ ]]; then
    flush_file
    current_file="${BASH_REMATCH[1]}"
    buffer=""
    continue
  fi
  if [[ "$line" =~ ^\*\*\*\  ]]; then
    flush_file
    continue
  fi
  if [[ -n "$current_file" ]]; then
    if [[ "$line" == +* ]]; then
      buffer+="${line:1}"$'\n'
    fi
  fi
done < "$tmp"
flush_file
rm -f "$tmp"
echo "Done."
