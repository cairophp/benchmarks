#!/usr/bin/env python3
"""compare.py <dir> <label-a> <label-b>: mean and standard error of each
core operation over the runs <dir>/coreops-<label>-N.txt (the output of
run.sh), and of the difference between the two labels."""
import glob, math, re, sys

d, a, b = sys.argv[1:4]


def runs(label):
    out = {}
    for f in sorted(glob.glob(f"{d}/coreops-{label}-*.txt")):
        for line in open(f):
            m = re.match(r"(.+?)\s+(\d+) ns elapsed\s+(\d+) ns CPU", line)
            if m:
                out.setdefault(m.group(1), []).append(int(m.group(2)))
    return out


def mean(v):
    return sum(v) / len(v)


def se(v):
    m = mean(v)
    return math.sqrt(sum((x - m) ** 2 for x in v) / (len(v) - 1) / len(v))


ra, rb = runs(a), runs(b)
for name in ra:
    xa, xb = ra[name], rb.get(name, [])
    if not xb:
        continue
    diff = mean(xb) - mean(xa)
    sed = math.sqrt(se(xa) ** 2 + se(xb) ** 2)
    print(f"{name[:44]:<44} {a} {mean(xa):9.1f} ± {se(xa):7.1f}   {b} {mean(xb):9.1f} ± {se(xb):7.1f}   diff {diff:+9.1f} ({100 * diff / mean(xa):+5.1f}%) ± {sed:7.1f} ({100 * sed / mean(xa):4.1f}%)  {abs(diff) / sed if sed else 0:4.1f} se  n={len(xa)},{len(xb)}")
