import json
from pathlib import Path
import tempfile
import unittest
from datetime import datetime
from unittest.mock import patch
import urllib.error

import collector as c

ROOT = Path(__file__).resolve().parents[1]


class CollectorTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.config = json.loads((ROOT / 'config/sources.json').read_text())
        cls.source = next(s for s in cls.config['sources'] if s['id'] == 'le-figaro')
        cls.now = datetime(2026, 10, 7, 11, 0, tzinfo=c.PARIS)

    def item(self, **changes):
        value = dict(title='Arcachon : sécurité en mer', url='https://www.lefigaro.fr/article',
                     published_at=c.parse_date('2026-09-30T23:30:00Z'), modified_at=None,
                     description='Une proposition locale.')
        value.update(changes)
        return value

    def test_rss_parsing_removes_html_and_uses_publication(self):
        data = b'''<rss><channel><item><title>Arcachon</title><link>https://www.lefigaro.fr/a</link>
        <pubDate>Wed, 30 Sep 2026 08:00:00 +0200</pubDate><description>&lt;p&gt;Texte&lt;/p&gt;&lt;script&gt;bad&lt;/script&gt;</description></item></channel></rss>'''
        item = c.parse_feed(data)[0]
        self.assertEqual(item['description'], 'Texte')
        self.assertEqual(item['published_at'].date().isoformat(), '2026-09-30')

    def test_atom_updated_is_not_a_publication_date(self):
        data = b'''<feed xmlns="http://www.w3.org/2005/Atom"><entry><title>Arcachon</title>
        <link href="https://www.lefigaro.fr/a"/><updated>2026-09-30T08:00:00Z</updated></entry></feed>'''
        item = c.parse_feed(data)[0]
        self.assertIsNone(item['published_at'])
        self.assertIsNotNone(item['modified_at'])
        self.assertEqual(item['url'], 'https://www.lefigaro.fr/a')

    def test_paris_midnight_boundary(self):
        article, reason = c.normalize_item(self.item(), self.source, self.config, self.now)
        self.assertIsNone(reason)
        self.assertEqual(article['publication_date'], '2026-10-01')

    def test_ambiguous_places_and_foreign_links_are_rejected(self):
        _, reason = c.normalize_item(self.item(title='Salles : le conseil municipal'), self.source, self.config, self.now)
        self.assertEqual(reason, 'outside_territory')
        _, reason = c.normalize_item(self.item(url='https://lefigaro.fr.evil.example/a'), self.source, self.config, self.now)
        self.assertEqual(reason, 'foreign_domain')

    def test_missing_and_future_dates_are_rejected(self):
        _, reason = c.normalize_item(self.item(published_at=None), self.source, self.config, self.now)
        self.assertEqual(reason, 'missing_date_or_title')
        _, reason = c.normalize_item(self.item(published_at=c.parse_date('2027-01-01T12:00:00Z')), self.source, self.config, self.now)
        self.assertEqual(reason, 'future_date')

    def test_tracking_dedup_and_publication_preserved(self):
        a, _ = c.normalize_item(self.item(url='https://www.lefigaro.fr/a?utm_source=x&fbclid=y'), self.source, self.config, self.now)
        b, _ = c.normalize_item(self.item(url='https://www.lefigaro.fr/a', published_at=self.now), self.source, self.config, self.now)
        archive = {}; c.merge(archive, a); c.merge(archive, b)
        self.assertEqual(len(archive), 1)
        self.assertEqual(archive[a['id']]['publication_date'], '2026-10-01')

    def test_xml_entities_and_html_response_rejected(self):
        with self.assertRaises(ValueError): c.parse_feed(b'<!DOCTYPE rss [<!ENTITY x "x">]><rss/>')
        with self.assertRaises(ValueError): c.parse_feed(b'<html><body>Forbidden</body></html>')

    def test_failed_feed_has_status_not_zero_publications(self):
        with patch('urllib.request.urlopen', side_effect=urllib.error.URLError('indisponible')):
            records, status = c.fetch_feed(self.source, self.source['feeds'][0], self.config, self.now)
        self.assertEqual(records, [])
        self.assertEqual(status['status'], 'error')
        self.assertIn('error', status)

    def test_total_failure_keeps_existing_files(self):
        with tempfile.TemporaryDirectory() as d:
            archive = Path(d) / 'archive.json'; archive.write_text('{}')
            output = Path(d) / 'public'; output.mkdir()
            index = output / 'index.json'; index.write_text('previous-valid-export')
            args = ['collector.py', '--config', str(ROOT / 'config/sources.json'),
                    '--archive', str(archive), '--output', str(output)]
            with patch('sys.argv', args), patch('urllib.request.urlopen', side_effect=urllib.error.URLError('down')), patch('sys.stderr'):
                self.assertEqual(c.main(), 1)
            self.assertEqual(archive.read_text(), '{}')
            self.assertEqual(index.read_text(), 'previous-valid-export')

    def test_curated_seed_and_week_month_exports(self):
        archive = {}; c.apply_curated(archive, json.loads((ROOT / 'data/curated.json').read_text()), self.config, self.now)
        self.assertEqual(len(archive), 31)
        self.assertTrue(all(a['publication_time_precision'] == 'day' for a in archive.values()))
        with tempfile.TemporaryDirectory() as d:
            c.export(archive, self.config, [], d, self.now)
            month = json.loads((Path(d) / 'mois/2026-09.json').read_text())
            week = json.loads((Path(d) / 'derniere-semaine.json').read_text())
            self.assertEqual(len(month['articles']), 31)
            self.assertEqual(week['period']['start'], '2026-09-28')
            self.assertEqual(week['period']['end'], '2026-10-04')
            self.assertTrue(all('2026-09-28' <= a['publication_date'] <= '2026-10-04' for a in week['articles']))

    def test_manual_correction_is_not_overwritten_by_rss(self):
        archive = {}; c.apply_curated(archive, json.loads((ROOT / 'data/curated.json').read_text()), self.config, self.now)
        original = next(iter(archive.values())).copy()
        incoming = dict(original, title='Titre modifié par le flux', editorial_override=False)
        c.merge(archive, incoming)
        self.assertEqual(archive[original['id']]['title'], original['title'])


if __name__ == '__main__': unittest.main()
