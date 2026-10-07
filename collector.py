#!/usr/bin/env python3
"""Collecteur RSS/Atom, sans dépendance Python externe. Python >= 3.11."""
import argparse
import calendar
import concurrent.futures
import hashlib
import html
from html.parser import HTMLParser
import json
import os
from pathlib import Path
import re
import sys
import tempfile
import unicodedata
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from datetime import datetime, timedelta, timezone
from email.utils import parsedate_to_datetime
from zoneinfo import ZoneInfo

PARIS = ZoneInfo('Europe/Paris')
MAX_FEED_BYTES = 8 * 1024 * 1024
TRACKING = {'fbclid', 'gclid', 'sdb_date', 'at_medium', 'at_campaign', 'xtor', 'output'}


def fold(value):
    return ''.join(c for c in unicodedata.normalize('NFKD', value.lower())
                   if not unicodedata.combining(c)).replace('’', "'")


class PlainText(HTMLParser):
    def __init__(self):
        super().__init__(); self.parts = []; self.hidden = 0

    def handle_starttag(self, tag, attrs):
        if tag in ('script', 'style'): self.hidden += 1
        elif tag in ('p', 'br', 'div', 'li'): self.parts.append(' ')

    def handle_endtag(self, tag):
        if tag in ('script', 'style'): self.hidden = max(0, self.hidden - 1)
        elif tag in ('p', 'div', 'li'): self.parts.append(' ')

    def handle_data(self, data):
        if not self.hidden: self.parts.append(data)


def plain(value, limit=600):
    parser = PlainText(); parser.feed(value or '')
    text = re.sub(r'\s+', ' ', html.unescape(''.join(parser.parts))).strip()
    if len(text) <= limit: return text
    return text[:limit].rsplit(' ', 1)[0] + '…'


def canonical_url(value):
    p = urllib.parse.urlsplit(html.unescape(value.strip()))
    if p.scheme not in ('http', 'https') or not p.hostname or p.username or p.password:
        raise ValueError('URL article non HTTP(S)')
    query = [(k, v) for k, v in urllib.parse.parse_qsl(p.query, keep_blank_values=True)
             if k.lower() not in TRACKING and not k.lower().startswith('utm_')]
    return urllib.parse.urlunsplit((p.scheme.lower(), p.netloc.lower(), p.path or '/',
                                    urllib.parse.urlencode(sorted(query)), ''))


def parse_date(value):
    if not value: return None
    try: date = datetime.fromisoformat(value.strip().replace('Z', '+00:00'))
    except ValueError:
        try: date = parsedate_to_datetime(value.strip())
        except (ValueError, TypeError, OverflowError): return None
    # Une date sans fuseau dans un flux est ambiguë : elle n'est pas inventée.
    if date.tzinfo is None: return None
    return date.astimezone(PARIS)


def child_text(node, names):
    for name in names:
        for element in node:
            if element.tag.rsplit('}', 1)[-1] == name:
                return ''.join(element.itertext()).strip()
    return ''


def parse_feed(data):
    if re.search(br'<!\s*(DOCTYPE|ENTITY)', data, re.I):
        raise ValueError('Déclaration XML externe interdite')
    root = ET.fromstring(data)
    kind = root.tag.rsplit('}', 1)[-1]
    if kind not in ('rss', 'feed', 'RDF'): raise ValueError('Réponse non RSS/Atom')
    items = []
    for node in root.iter():
        if node.tag.rsplit('}', 1)[-1] not in ('item', 'entry'): continue
        link = child_text(node, ['link'])
        for element in node:
            if element.tag.rsplit('}', 1)[-1] == 'link' and element.get('href'):
                if element.get('rel', 'alternate') == 'alternate':
                    link = element.get('href'); break
        items.append({
            'title': plain(child_text(node, ['title']), 300),
            'url': link,
            'published_at': parse_date(child_text(node, ['pubDate', 'published', 'date'])),
            'modified_at': parse_date(child_text(node, ['updated'])),
            # Jamais de corps intégral : seulement la notice fournie par le flux.
            'description': plain(child_text(node, ['description', 'summary']), 450),
        })
    return items


def contains(text, term):
    return re.search(r'(?<!\w)' + re.escape(fold(term)) + r'(?!\w)', text) is not None


def classify(title, description, config):
    text = fold(title + ' ' + description)
    places = [place['name'] for place in config['places']
              if any(contains(text, alias) for alias in place['aliases'])]
    strong = any(contains(text, term) for term in config['territory_terms'])
    # Les noms ambigus (Salles, Arès...) seuls ne suffisent pas pour un média national.
    return text, places, strong


def theme_for(title, description, config):
    text = fold(title + ' ' + description)
    for theme in config['themes']:
        if any(contains(text, term) for term in theme['keywords']): return theme['id']
    return 'vie-locale'


def topic_for(title, description, config):
    text = fold(title + ' ' + description)
    for topic in config.get('topics', []):
        if all(any(contains(text, term) for term in group) for group in topic['all_groups']):
            return topic['id']
    return None


def normalize_item(item, source, config, now):
    if not item['title'] or not item['published_at']: return None, 'missing_date_or_title'
    date = item['published_at']
    if date > now + timedelta(hours=2): return None, 'future_date'
    try: url = canonical_url(item['url'])
    except ValueError: return None, 'invalid_url'
    host = urllib.parse.urlsplit(url).hostname
    if not any(host == domain or host.endswith('.' + domain) for domain in source['domains']):
        return None, 'foreign_domain'
    _, places, strong = classify(item['title'], item['description'], config)
    if not source.get('local') and not strong: return None, 'outside_territory'
    if source.get('exclude_paths') and any(path in urllib.parse.urlsplit(url).path
                                          for path in source['exclude_paths']):
        return None, 'excluded_path'
    stamp = now.isoformat(timespec='seconds')
    return {
        'id': hashlib.sha256(url.encode()).hexdigest()[:24],
        'title': item['title'], 'url': url,
        'source_id': source['id'], 'source_name': source['name'],
        'published_at': date.isoformat(timespec='seconds'),
        'modified_at': item['modified_at'].isoformat(timespec='seconds') if item['modified_at'] else None,
        'publication_date': date.date().isoformat(),
        'theme': theme_for(item['title'], item['description'], config),
        'places': places, 'topic_id': topic_for(item['title'], item['description'], config),
        'description': item['description'], 'description_origin': 'rss' if item['description'] else None,
        'verification': 'rss_notice', 'first_seen_at': stamp, 'last_seen_at': stamp,
    }, None


def merge(archive, article):
    old = archive.get(article['id'])
    if old:
        article['first_seen_at'] = old['first_seen_at']
        # La publication initialement constatée est conservée : un flux peut la remplacer
        # par sa date de mise à jour. Une correction explicite reste possible dans curated.json.
        article['published_at'] = old['published_at']
        article['publication_date'] = old['publication_date']
        if not article.get('description'):
            article['description'] = old.get('description', '')
            article['description_origin'] = old.get('description_origin')
        if not article.get('modified_at'): article['modified_at'] = old.get('modified_at')
        if old.get('editorial_override'): return
    archive[article['id']] = article


def apply_curated(archive, records, config, now):
    for record in records:
        source = next(s for s in config['sources'] if s['id'] == record['source_id'])
        date = parse_date(record['published_at'])
        if not date: raise ValueError('Date éditoriale invalide')
        item = {'title': record['title'], 'url': record['url'], 'published_at': date,
                'modified_at': None, 'description': record.get('description', '')}
        # Une référence éditoriale a déjà été sélectionnée ; le contrôle d'URL reste actif.
        article, error = normalize_item(item, dict(source, local=True), config, now)
        if error: raise ValueError('Référence éditoriale invalide : ' + error)
        article['verification'] = record.get('verification', 'reference_manually_reviewed')
        article['description_origin'] = 'editorial' if article['description'] else None
        article['editorial_override'] = True
        article['title_origin'] = 'editorial_abbreviation'
        article['publication_time_precision'] = record.get('publication_time_precision', 'instant')
        if 'theme' in record:
            if record['theme'] not in {t['id'] for t in config['themes']}:
                raise ValueError('Thème éditorial inconnu')
            article['theme'] = record['theme']
        if 'topic_id' in record: article['topic_id'] = record['topic_id']
        old = archive.get(article['id'])
        if old:
            article['first_seen_at'] = old['first_seen_at']
            article['last_seen_at'] = old['last_seen_at']
        archive[article['id']] = article


def fetch_feed(source, url, config, now):
    status = {'source_id': source['id'], 'source_name': source['name'], 'feed_url': url,
              'checked_at': now.isoformat(timespec='seconds'), 'status': 'error',
              'items_seen': 0, 'articles_selected': 0, 'skipped': {}}
    try:
        req = urllib.request.Request(url, headers={'User-Agent': config['user_agent'],
                                                   'Accept': 'application/rss+xml, application/atom+xml, application/xml'})
        with urllib.request.urlopen(req, timeout=config.get('timeout_seconds', 20)) as response:
            data = response.read(MAX_FEED_BYTES + 1)
        if len(data) > MAX_FEED_BYTES: raise ValueError('Flux trop volumineux')
        items = parse_feed(data); status['items_seen'] = len(items); articles = []
        for item in items:
            article, reason = normalize_item(item, source, config, now)
            if article: articles.append(article)
            else: status['skipped'][reason] = status['skipped'].get(reason, 0) + 1
        status.update(status='ok', articles_selected=len(articles))
        return articles, status
    except (urllib.error.URLError, OSError, ET.ParseError, ValueError) as error:
        status['error'] = plain(str(error), 200)
        return [], status


def atomic_json(path, value):
    path = Path(path); path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile('w', encoding='utf-8', dir=path.parent, delete=False) as f:
        json.dump(value, f, ensure_ascii=False, indent=2); f.write('\n'); tmp = f.name
    os.replace(tmp, path)


def export(archive, config, statuses, output, now):
    output = Path(output); output.mkdir(parents=True, exist_ok=True)
    today = now.astimezone(PARIS).date()
    monday = today - timedelta(days=today.weekday())
    last_monday = monday - timedelta(days=7)
    articles = sorted(archive.values(), key=lambda a: (a['published_at'], a['id']), reverse=True)
    themes = [{'id': t['id'], 'label': t['label']} for t in config['themes']]
    topics = [{'id': t['id'], 'label': t['label']} for t in config.get('topics', [])]
    stamp = now.isoformat(timespec='seconds')
    periods = []

    def period(key, label, start, end, filename):
        selected = [a for a in articles if start <= a['publication_date'] <= end]
        info = {'id': key, 'label': label, 'start': start, 'end': end,
                'url': filename, 'article_count': len(selected)}
        atomic_json(output / filename, {'schema_version': 1, 'generated_at': stamp,
                    'timezone': 'Europe/Paris', 'period': info, 'themes': themes,
                    'topics': topics, 'articles': selected})
        periods.append(info)

    period('latest-complete-week', 'Dernière semaine complète', last_monday.isoformat(),
           (monday - timedelta(days=1)).isoformat(), 'derniere-semaine.json')
    period('current-week', 'Semaine en cours', monday.isoformat(),
           (monday + timedelta(days=6)).isoformat(), 'semaine-courante.json')
    for month in sorted({a['publication_date'][:7] for a in articles}, reverse=True):
        year, number = map(int, month.split('-'))
        period(month, month, month + '-01', f'{month}-{calendar.monthrange(year, number)[1]}',
               f'mois/{month}.json')
    atomic_json(output / 'index.json', {'schema_version': 1, 'generated_at': stamp,
                'timezone': 'Europe/Paris', 'default_period': 'latest-complete-week',
                'coverage_note': 'Sélection non exhaustive. Les flux ne permettent pas une reconstitution historique complète.',
                'curated_note': 'Les références de septembre 2026 sont une sélection initiale partielle, aux titres abrégés.',
                'sources': statuses, 'themes': themes, 'topics': topics, 'periods': periods})
    (output / '.nojekyll').write_text('', encoding='utf-8')
    (output / 'index.html').write_text('<!doctype html><html lang="fr"><meta charset="utf-8">'
        '<title>Revue de presse du Bassin — données</title><h1>Revue de presse du Bassin</h1>'
        '<p>Fichiers publics pour le plugin WordPress.</p><a href="index.json">Index JSON</a></html>', encoding='utf-8')


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--config', default='config/sources.json')
    p.add_argument('--archive', default='data/archive.json')
    p.add_argument('--curated', default='data/curated.json')
    p.add_argument('--output', default='public')
    p.add_argument('--offline', action='store_true', help='Export des archives sans collecte réseau')
    p.add_argument('--source', help='Collecter uniquement ce média (diagnostic)')
    args = p.parse_args()
    config = json.loads(Path(args.config).read_text(encoding='utf-8'))
    now = datetime.now(PARIS)
    archive_path = Path(args.archive)
    archive = json.loads(archive_path.read_text(encoding='utf-8')) if archive_path.exists() else {}
    jobs = [(s, u) for s in config['sources'] if s.get('enabled', True)
            and (not args.source or s['id'] == args.source) for u in s['feeds']]
    if args.source and not jobs: p.error('Média inconnu ou désactivé')
    statuses = []
    if not args.offline:
        with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
            futures = [pool.submit(fetch_feed, source, url, config, now) for source, url in jobs]
            for future in futures:
                records, status = future.result(); statuses.append(status)
                for record in records: merge(archive, record)
        if not any(s['status'] == 'ok' for s in statuses):
            print(json.dumps({'error': 'Tous les flux ont échoué. Exports précédents conservés.',
                              'sources': statuses}, ensure_ascii=False), file=sys.stderr)
            return 1
    else:
        statuses = [{'source_id': s['id'], 'source_name': s['name'], 'feed_url': u,
                     'status': 'not_checked', 'checked_at': None} for s, u in jobs]
    curated_path = Path(args.curated)
    if curated_path.exists(): apply_curated(archive, json.loads(curated_path.read_text(encoding='utf-8')), config, now)
    # Les corrections manuelles sont protégées des prochaines observations RSS.
    for article_id in config.get('excluded_article_ids', []): archive.pop(article_id, None)
    atomic_json(args.archive, archive)
    export(archive, config, statuses, args.output, now)
    print(json.dumps({'archive_articles': len(archive), 'feeds_ok': sum(s['status'] == 'ok' for s in statuses),
                      'feeds_total': len(statuses), 'output': args.output}, ensure_ascii=False))
    return 0


if __name__ == '__main__': sys.exit(main())
