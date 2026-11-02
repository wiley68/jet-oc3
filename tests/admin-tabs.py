#!/usr/bin/env python3
"""Strict structural contract for the actual admin Twig; no browser/Twig dependency."""
from html.parser import HTMLParser
from pathlib import Path
import re
import subprocess
ROOT = Path(__file__).resolve().parents[1]
source = (ROOT/'upload/admin/view/template/extension/module/mt_jet_credit.twig').read_text()
checks = 0
def check(value, message):
    global checks
    checks += 1
    if not value: raise AssertionError(message)
class Node:
    def __init__(self, tag, attrs=(), parent=None):
        self.tag, self.attrs, self.parent = tag, dict(attrs), parent
    def has(self, cls): return cls in self.attrs.get('class','').split()
    def ancestor(self, tag):
        n = self.parent
        while n:
            if n.tag == tag: return n
            n = n.parent
class Tree(HTMLParser):
    void = {'input','img','br','hr','meta','link','area','base','col','embed','param','source','track','wbr'}
    def __init__(self):
        super().__init__(); self.stack=[]; self.nodes=[]
    def handle_starttag(self, tag, attrs):
        n=Node(tag,attrs,self.stack[-1] if self.stack else None);self.nodes.append(n)
        if tag not in self.void:self.stack.append(n)
    def handle_startendtag(self, tag, attrs):
        self.handle_starttag(tag,attrs)
        if tag not in self.void:self.handle_endtag(tag)
    def handle_endtag(self, tag):
        check(bool(self.stack) and self.stack[-1].tag==tag, f'unbalanced {tag} at {self.getpos()}')
        self.stack.pop()
def parse(text):
    # Preserve all balanced Twig branches/loop bodies, neutralize attribute outputs.
    text=re.sub(r'{{.*?}}','fixture',text,flags=re.S)
    text=re.sub(r'{%.*?%}','',text,flags=re.S)
    p=Tree();p.feed(text);check(not p.stack,'all containers closed');return p
p=parse(source)
ids=[n.attrs['id'] for n in p.nodes if 'id' in n.attrs]
check(len(ids)==len(set(ids)),'unique DOM IDs')
panes=[n for n in p.nodes if n.has('tab-pane')]
links=[n for n in p.nodes if n.attrs.get('data-toggle')=='tab']
check(len(panes)==len(links)==4,'four tabs and panes')
check({n.attrs['href'][1:] for n in links}=={n.attrs['id'] for n in panes},'one-to-one targets')
check(sum(n.has('active') for n in panes)==1,'one active pane')
check(sum(n.parent.has('active') for n in links)==1,'one active nav item')
check(next(n for n in links if n.parent.has('active')).attrs['href']=='#'+next(n for n in panes if n.has('active')).attrs['id'],'matching initial active tab')
for pane in panes:
    check(pane.parent.has('tab-content'),'Bootstrap 3 direct pane children')
    check(pane.ancestor('form').attrs.get('id')=='form-html','each pane inside settings form')
    check(not pane.has('show') and not pane.has('fade'),'no Bootstrap 4 show/fade dependency')
check('tab-content > form > .tab-pane' not in source,'no obsolete wrapper CSS override')
for field,tab in {'status':'upravlenie','cart_show':'upravlenie','status_card':'upravlenie','vnoska':'upravlenie','mail':'funkcionalni','id':'funkcionalni','price':'funkcionalni','purcent':'funkcionalni','purcent_card':'funkcionalni','vnoski':'funkcionalni','gap':'vizualni','button_type':'vizualni','button_scheme':'vizualni','btn_logo':'vizualni','btn_text':'vizualni','btn_text_card':'vizualni','btn_max_width':'vizualni','btn_font':'vizualni','btn_round':'vizualni'}.items():
    nodes=[n for n in p.nodes if n.attrs.get('name')=='module_mt_jet_credit_'+field]
    check(bool(nodes),'field remains present '+field)
    for n in nodes:
        check(n.ancestor('form').attrs.get('id')=='form-html','successful form owner '+field)
        parent=n.parent
        while parent and not parent.has('tab-pane'):parent=parent.parent
        check(parent and parent.attrs['id']=='mt-jet-tab-'+tab,'field designated pane '+field)
for n in p.nodes:
    if n.attrs.get('id','').startswith('module_mt_jet_credit_product_'):
        check(n.ancestor('form') is not None,'promotion inputs inside form')
identifier=next(n for n in p.nodes if n.attrs.get('id')=='module_mt_jet_credit_id')
check('required' in identifier.attrs,'only mandatory text identifier marked')
check(".on('invalid', function ()" in source and "a[href=\"#mt-jet-tab-funkcionalni\"]" in source,'required hidden field opens its Bootstrap pane')
check(not any('required' in n.attrs for n in p.nodes if n.attrs.get('name')!='module_mt_jet_credit_id'),'optional controls not required')
for token in ('{{ header }}','{{ column_left }}','{{ footer }}'):check(source.count(token)==1,'single layout composition '+token)
# The Bootstrap direct-child contract is the visibility mechanism; no custom tab implementation.
check(all(n.attrs.get('data-toggle')=='tab' for n in links),'native Bootstrap tab data API')
check(".tab('show')" in source and 'shown.bs.tab' in source,'native plugin used for restored tab')
# Real libxml DOM repair independently checks all panes and recipient form ownership.
php=r'''libxml_use_internal_errors(true);$d=new DOMDocument();$d->loadHTML(stream_get_contents(STDIN));$x=new DOMXPath($d);foreach($x->query('//*[contains(concat(" ",normalize-space(@class)," ")," tab-pane ")]')as$p){if($x->query('ancestor::form[@id="form-html"]',$p)->length!==1)exit(1);}if($x->query('//form[@id="form-html"]//*[@name="module_mt_jet_credit_mail"]')->length!==1)exit(2);'''
html=re.sub(r'{%.*?%}','',re.sub(r'{{.*?}}','fixture',source,flags=re.S),flags=re.S)
r=subprocess.run(['php','-r',php],input=html,text=True,capture_output=True)
check(r.returncode==0,'independent DOM parser form association '+r.stderr)
print(f'T-ADMIN-TABS PASS: {checks} checks; strict markup and DOM ownership; browser/Twig rendering deferred.')
