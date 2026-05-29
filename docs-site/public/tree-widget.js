(function () {
    'use strict';

    // =======================================================================
    // Nested-set tree widget
    //
    // Split into two layers around an explicit data contract so the same
    // renderer works whether the data is computed in the browser (today) or
    // returned by the package over Eloquent (the planned Laravel demo app):
    //
    //   TreeData = {
    //     metric: null | { name: string },   // the rolled-up aggregate, if any
    //     nodes:  Node[]                      // flat list, any order
    //   }
    //   Node = {
    //     id:       string | number,
    //     parentId: string | number | null,  // null (or out-of-set) => a root
    //     name:     string,
    //     lft:      number,
    //     rgt:      number,
    //     depth:    number,
    //     value:    number | null,            // this row's own source value
    //     rollup:   number | null,            // SUM over its subtree
    //     chips:    [string, string|null][]   // extra display chips
    //   }
    //
    // `fromText()` builds TreeData from the ```ns-tree authoring format.
    // A server can build the identical shape directly: a `defaultOrder()`
    // query returns flat rows carrying parent_id / lft / rgt / depth, and the
    // maintained `<name>_total` column is the per-row `rollup`. Either way,
    // `render(mountEl, data)` draws the widget. Both are exposed on
    // `window.NestedTree` for the future app to reuse.
    //
    // Authoring format — indentation is the hierarchy; a numeric brace
    // annotation becomes the aggregate source value, rolled up every ancestor:
    //
    //     ```ns-tree
    //     Electronics
    //       Phones
    //         Android {products=37}
    //         iOS {products=15}
    //     ```
    // =======================================================================

    var TAB = '    ';
    var NUMERIC = /^-?\d+(?:\.\d+)?$/;

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function fmt(n) {
        return Number.isInteger(n) ? String(n) : String(Math.round(n * 100) / 100);
    }

    function el(tag, cls) {
        var node = document.createElement(tag);
        if (cls) {
            node.className = cls;
        }
        return node;
    }

    // ----- authoring adapter: indented text -> TreeData --------------------

    function leadingWidth(line) {
        var i = 0;
        while (i < line.length && line[i] === ' ') {
            i++;
        }
        return i;
    }

    // "Phones {sales=20, hot=true}" -> { name, chips: [['sales','20'], ...] }
    function parseLabel(text) {
        var chips = [];
        var name = text;
        var brace = text.indexOf('{');
        if (brace !== -1 && text.trim().slice(-1) === '}') {
            name = text.slice(0, brace).trim();
            var inner = text.slice(brace + 1, text.lastIndexOf('}'));
            inner.split(',').forEach(function (pair) {
                pair = pair.trim();
                if (!pair) {
                    return;
                }
                var eq = pair.search(/[=:]/);
                if (eq === -1) {
                    chips.push([pair, null]);
                } else {
                    chips.push([pair.slice(0, eq).trim(), pair.slice(eq + 1).trim()]);
                }
            });
        }
        return { name: name, chips: chips };
    }

    function parseIndent(source) {
        var roots = [];
        var stack = []; // { width, node }

        source.split('\n').forEach(function (raw) {
            var line = raw.replace(/\t/g, TAB).replace(/\s+$/, '');
            if (line.trim() === '') {
                return;
            }
            var width = leadingWidth(line);
            var parsed = parseLabel(line.trim());
            var node = { name: parsed.name, chips: parsed.chips, children: [] };

            while (stack.length && stack[stack.length - 1].width >= width) {
                stack.pop();
            }
            if (stack.length === 0) {
                roots.push(node);
            } else {
                stack[stack.length - 1].node.children.push(node);
            }
            stack.push({ width: width, node: node });
        });

        return roots;
    }

    function fromText(source) {
        var roots = parseIndent(source);
        if (roots.length === 0) {
            return { metric: null, nodes: [] };
        }

        // Depth-first lft/rgt/depth assignment — the canonical nested-set walk.
        var refs = [];
        var counter = 1;
        var nextId = 1;
        function walk(node, depth, parentId) {
            node.id = nextId++;
            node.parentId = parentId;
            node.depth = depth;
            node.lft = counter++;
            refs.push(node);
            node.children.forEach(function (c) { walk(c, depth + 1, node.id); });
            node.rgt = counter++;
        }
        roots.forEach(function (r) { walk(r, 0, null); });

        // Pull the source value out of each node's chips and derive the metric.
        var metricName = null;
        var hasMetric = false;
        refs.forEach(function (node) {
            node.value = null;
            node.rest = [];
            node.chips.forEach(function (chip) {
                var key = chip[0];
                var val = chip[1];
                if (node.value === null && val !== null && NUMERIC.test(val)) {
                    node.value = Number(val);
                    if (metricName === null) {
                        metricName = key;
                    }
                    hasMetric = true;
                } else if (node.value === null && val === null && NUMERIC.test(key)) {
                    node.value = Number(key);
                    hasMetric = true;
                } else {
                    node.rest.push(chip);
                }
            });
        });

        // Roll values up each subtree as a SUM (the maintained *_total column).
        function rollup(node) {
            var sum = node.value || 0;
            node.children.forEach(function (c) { sum += rollup(c); });
            node.rollup = sum;
            return sum;
        }
        roots.forEach(rollup);

        var nodes = refs.map(function (node) {
            return {
                id: node.id,
                parentId: node.parentId,
                name: node.name,
                lft: node.lft,
                rgt: node.rgt,
                depth: node.depth,
                value: hasMetric ? node.value : null,
                rollup: hasMetric ? node.rollup : null,
                chips: node.rest,
            };
        });

        return { metric: hasMetric ? { name: metricName || 'value' } : null, nodes: nodes };
    }

    // ----- renderer: TreeData -> interactive DOM ---------------------------

    function buildIndex(nodes) {
        var byId = Object.create(null);
        nodes.forEach(function (n) { byId[n.id] = n; });

        var childrenOf = Object.create(null);
        var roots = [];
        nodes.forEach(function (n) {
            if (n.parentId === null || n.parentId === undefined || !(n.parentId in byId)) {
                roots.push(n);
            } else {
                (childrenOf[n.parentId] || (childrenOf[n.parentId] = [])).push(n);
            }
        });

        var byLft = function (a, b) { return a.lft - b.lft; };
        roots.sort(byLft);
        Object.keys(childrenOf).forEach(function (k) { childrenOf[k].sort(byLft); });

        return { childrenOf: childrenOf, roots: roots };
    }

    function renderNode(node, ctx) {
        var li = el('li', 'ns-item');
        li.setAttribute('role', 'treeitem');

        var row = el('button', 'ns-node');
        row.type = 'button';

        var name = el('span', 'ns-node-name');
        name.textContent = node.name;
        row.appendChild(name);

        if (ctx.hasMetric && node.value !== null && node.value !== undefined) {
            var own = el('span', 'ns-chip ns-own');
            own.textContent = ctx.metricName + ' ' + fmt(node.value);
            row.appendChild(own);
        }

        (node.chips || []).forEach(function (chip) {
            var span = el('span', 'ns-chip');
            span.textContent = chip[1] === null ? chip[0] : chip[0] + ' ' + chip[1];
            row.appendChild(span);
        });

        var trailing = el('span', 'ns-trailing');

        if (ctx.hasMetric && node.rollup !== null && node.rollup !== undefined) {
            var roll = el('span', 'ns-rollup');
            roll.title = 'SUM(' + ctx.metricName + ') across this subtree';
            roll.textContent = 'Σ ' + fmt(node.rollup);
            trailing.appendChild(roll);
        }

        var bounds = el('span', 'ns-bounds');
        var l = el('span', 'ns-lft');
        l.textContent = String(node.lft);
        var r = el('span', 'ns-rgt');
        r.textContent = String(node.rgt);
        bounds.appendChild(l);
        bounds.appendChild(r);
        trailing.appendChild(bounds);
        row.appendChild(trailing);

        row.addEventListener('click', function () { ctx.select(node); });
        row.addEventListener('mouseenter', function () { ctx.preview(node); });
        row.addEventListener('mouseleave', function () { ctx.clearPreview(); });

        li.appendChild(row);
        ctx.items.push({ node: node, li: li });

        var kids = ctx.childrenOf[node.id] || [];
        if (kids.length) {
            li.setAttribute('aria-expanded', 'true');
            var ul = el('ul', 'ns-children');
            ul.setAttribute('role', 'group');
            kids.forEach(function (child) { ul.appendChild(renderNode(child, ctx)); });
            li.appendChild(ul);
        }

        return li;
    }

    function describe(node, ctx) {
        var count = (node.rgt - node.lft + 1) / 2;
        var panel = el('div', 'ns-sql');

        var title = el('div', 'ns-sql-title');
        title.textContent = 'Subtree of “' + node.name + '”';
        panel.appendChild(title);

        var pre = el('pre', 'ns-sql-code');
        var note = el('div', 'ns-sql-note');

        if (ctx.hasMetric) {
            pre.textContent =
                'SELECT SUM(' + ctx.metricName + ') FROM nodes\n' +
                'WHERE lft BETWEEN ' + node.lft + ' AND ' + node.rgt + ';';
            note.innerHTML =
                'Returns <strong>' + fmt(node.rollup) + '</strong> over ' + count +
                ' node' + (count === 1 ? '' : 's') + '. laravel-nestedset keeps this in ' +
                '<code>' + node.name.toLowerCase().replace(/[^a-z0-9]+/g, '_') + '.' +
                ctx.metricName + '_total</code>, updated on every insert / move / delete — ' +
                'so reads need no subquery at all.';
        } else {
            pre.textContent =
                'SELECT * FROM nodes\n' +
                'WHERE lft BETWEEN ' + node.lft + ' AND ' + node.rgt + ';';
            note.innerHTML =
                '<strong>' + count + '</strong> node' + (count === 1 ? '' : 's') +
                ' &mdash; one indexed range scan, no recursion. Use the open ' +
                'interval (<code>BETWEEN ' + (node.lft + 1) + ' AND ' + (node.rgt - 1) +
                '</code>) for descendants only.';
        }

        panel.appendChild(pre);
        panel.appendChild(note);
        return panel;
    }

    function render(mount, data) {
        if (!data || !data.nodes || data.nodes.length === 0) {
            return null;
        }

        var index = buildIndex(data.nodes);
        var hasMetric = !!data.metric;

        var widget = el('div', 'ns-widget');

        var treeWrap = el('div', 'ns-tree');
        treeWrap.setAttribute('role', 'tree');
        treeWrap.setAttribute('aria-label', 'Nested-set tree demo');

        var aside = el('div', 'ns-aside');
        var hint = el('div', 'ns-hint');
        hint.textContent = hasMetric
            ? 'Each node shows Σ — the maintained SUM over its subtree. Select one to see the query behind it.'
            : 'Select a node to see the query for its subtree.';

        var legend = el('div', 'ns-legend');
        legend.innerHTML =
            '<span class="ns-key ns-key-selected">selected</span>' +
            '<span class="ns-key ns-key-descendant">subtree</span>' +
            '<span class="ns-key ns-key-ancestor">ancestors</span>';

        var ctx = {
            items: [],
            childrenOf: index.childrenOf,
            hasMetric: hasMetric,
            metricName: hasMetric ? data.metric.name : null,
            selectedId: null,
            clearClasses: function () {
                ctx.items.forEach(function (it) {
                    it.li.classList.remove(
                        'is-selected', 'is-descendant', 'is-ancestor', 'is-preview'
                    );
                });
            },
            paint: function (node, mode) {
                ctx.items.forEach(function (it) {
                    var n = it.node;
                    if (n.id === node.id) {
                        it.li.classList.add(mode === 'preview' ? 'is-preview' : 'is-selected');
                    } else if (n.lft > node.lft && n.rgt < node.rgt) {
                        it.li.classList.add('is-descendant');
                    } else if (n.lft < node.lft && n.rgt > node.rgt) {
                        it.li.classList.add('is-ancestor');
                    }
                });
            },
            select: function (node) {
                if (ctx.selectedId === node.id) {
                    ctx.selectedId = null;
                    ctx.clearClasses();
                    aside.replaceChildren(hint, legend);
                    return;
                }
                ctx.selectedId = node.id;
                ctx.clearClasses();
                ctx.paint(node, 'select');
                aside.replaceChildren(describe(node, ctx), legend);
            },
            preview: function (node) {
                if (ctx.selectedId !== null) {
                    return;
                }
                ctx.clearClasses();
                ctx.paint(node, 'preview');
            },
            clearPreview: function () {
                if (ctx.selectedId !== null) {
                    return;
                }
                ctx.clearClasses();
            },
        };

        var ul = el('ul', 'ns-children ns-root');
        index.roots.forEach(function (root) { ul.appendChild(renderNode(root, ctx)); });
        treeWrap.appendChild(ul);

        aside.appendChild(hint);
        aside.appendChild(legend);

        widget.appendChild(treeWrap);
        widget.appendChild(aside);
        mount.replaceWith(widget);
        return widget;
    }

    // ----- hydrate authoring blocks + expose the API -----------------------

    window.NestedTree = { fromText: fromText, render: render };

    ready(function () {
        var blocks = document.querySelectorAll('pre > code.language-ns-tree');
        Array.prototype.forEach.call(blocks, function (code) {
            render(code.parentElement, fromText(code.textContent));
        });
    });
})();
