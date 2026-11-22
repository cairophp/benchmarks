#!/usr/bin/env python3
"""rounds.py <log of rounds.sh> <base label> <label>...: requests per second
of each label, and each label against the base by paired rounds: the mean of
the per-round ratios with its 95% interval (1.96 standard errors)."""
import math
import statistics as st
import sys

rows = []
for line in open(sys.argv[1]):
    f = line.split()
    if len(f) < 4 or f[0] != 'round':
        continue
    if 'BAD' in line:
        print('left out, it had failed requests:', line.strip())
        continue
    rows.append({f[i]: float(f[i + 1]) for i in range(2, len(f) - 1, 2)})
base = sys.argv[2]
for label in sys.argv[2:]:
    v = [r[label] for r in rows]
    out = f"{label}: mean {st.mean(v):,.0f} median {st.median(v):,.0f} (n={len(v)})"
    if label != base:
        d = [r[label] / r[base] - 1 for r in rows]
        out += f"  vs {base}: {100 * st.mean(d):+.1f}% +- {100 * 1.96 * st.stdev(d) / math.sqrt(len(d)):.1f}%"
    print(out)
