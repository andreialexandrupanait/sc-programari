"""Construiește landing page-ul /sedinte-foto-de-craciun/ din cele patru părți.

Rulare (din rădăcina repo-ului):
    python3 dev/landing-page/build.py

Produce în build/ (ignorat de git):
  lp-complet.html  – tot HTML-ul, cu shortcode-urile incluse; se lipește într-un SINGUR widget
                     Elementor de tip Shortcode (nu HTML: widgetul HTML nu rulează shortcode-uri),
                     într-un container pe lățime completă, fără padding și fără gap.
  lp-elementor.json – structura cu widgeturi separate (HTML + Shortcode), pentru _elementor_data
                     sau pentru instrumentul de migrare (tools/.../data/export.json → page.meta._elementor_data).
"""
import json
import pathlib

HERE = pathlib.Path(__file__).resolve().parent
OUT = HERE.parent.parent / 'build'
REVIEWS = '[wprevpro_usetemplate tid="1"]'
FORM = '[sc_programare]'


def part(name: str) -> str:
    return (HERE / name).read_text(encoding='utf-8')


def complete() -> str:
    html = (
        part('partA.html')
        + f'\n<div class="sc6-revwrap elementor-element-sc6rev">{REVIEWS}</div>\n'
        + part('partB.html')
        + '\n<div class="sc6-bookwrap">\n' + part('partB2.html')
        + f'\n<div class="elementor-element-sc6form">{FORM}</div>\n</div>\n'
        + part('partC.html')
    )
    # fără containerul Elementor, conținutul trebuie să stea deasupra stratului verde
    return html.replace(
        '.sc6-bookwrap>.e-con-inner,.sc6-bookwrap>.elementor-element{position:relative}',
        '.sc6-bookwrap>.e-con-inner,.sc6-bookwrap>.elementor-element,.sc6-bookwrap>*{position:relative}',
        1,
    )


def elementor() -> list:
    pad0 = {'unit': 'px', 'top': '0', 'right': '0', 'bottom': '0', 'left': '0', 'isLinked': True}
    gap0 = {'unit': 'px', 'size': 0, 'column': '0', 'row': '0', 'isLinked': True}

    def html(i, n):
        return {'id': i, 'elType': 'widget', 'widgetType': 'html', 'settings': {'html': part(n)}, 'elements': []}

    def sc(i, s):
        return {'id': i, 'elType': 'widget', 'widgetType': 'shortcode', 'settings': {'shortcode': s}, 'elements': []}

    return [{
        'id': 'sc6root', 'elType': 'container', 'isInner': False,
        'settings': {'content_width': 'full', 'padding': pad0, 'padding_tablet': pad0, 'padding_mobile': pad0, 'flex_gap': gap0},
        'elements': [
            html('sc6a', 'partA.html'),
            sc('sc6rev', REVIEWS),
            html('sc6b', 'partB.html'),
            {'id': 'sc6book', 'elType': 'container', 'isInner': True,
             'settings': {'content_width': 'full', 'css_classes': 'sc6-bookwrap', 'padding': pad0, 'padding_tablet': pad0,
                          'padding_mobile': pad0, 'flex_gap': gap0},
             'elements': [html('sc6b2', 'partB2.html'), sc('sc6form', FORM)]},
            html('sc6c', 'partC.html'),
        ],
    }]


if __name__ == '__main__':
    OUT.mkdir(exist_ok=True)
    (OUT / 'lp-complet.html').write_text(complete(), encoding='utf-8')
    (OUT / 'lp-elementor.json').write_text(json.dumps(elementor(), ensure_ascii=False), encoding='utf-8')
    print('build/lp-complet.html, build/lp-elementor.json')
