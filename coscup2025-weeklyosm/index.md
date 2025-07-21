---
marp: true
title: "Behind the Scenes of weeklyOSM: How We Share OSM News Every Week"
institute: "COSCUP 2025"
author: "Hiroshi Miura"
date: "2025-08-10"
paginate: true
theme: gaia
header: 'Behind the Scenes of weeklyOSM'
footer: '2025.8.10, COSCUP 2025'
style: |
  section {
    background-image:
            url(images/Logo_weeklyOSM.svg),
            url(images/osm-background.png);
    background-position:
            right 30px top 30px,
            bottom right;
    background-size:
            250px 130px,
            1366px;
    background-repeat: no-repeat;
  }

---

<!-- Title Slide -->
<!-- _class: gaia lead -->
<!-- _paginate: false -->
<!-- _header: '' -->
<!-- _footer: '' -->

![bg right:40% 100%](images/banner-mobile.png)

#### Behind the Scenes of
#  <span style="color: purple;">weeklyOSM</span>
#### How We Share OSM News Every Week
### Hiroshi Miura, OSM Foundation Japan

---

## Agenda
1. The fact of 780+ Weeks
2. What is weeklyOSM?
4. The Magic Behind the Curtain
5. Deep Dive: OSMBC Tool
6. My Challenge: productivity
7. Join the Story

---

<!-- 講演者紹介 -->

![bg right:40% 50%](images/self-stand.jpg)

**Who am I**
- Hiroshi Miura
- **OpenStreetMap Japan** from 18 years ago
- Lead **developer** of translation assist application
- President of **OSM Foundation Japan**
- reach me at LinkedIn(@miurahr)

---

<!-- Opening Hook -->

### **770+ Weeks** of 
## **Never** Missing a Beat

---

![bg left:](images/weeklyosm_timeline.svg)

---


## The Global Pulse of OpenStreetMap

- **Connecting mappers worldwide**
- **Local stories → Global impact**
- **Community heartbeat**
- **Volunteer-driven journalism**

---

![bg right:](images/weeklyosm-global-map.png)

---

<!-- What is weeklyOSM -->

![bg right:30% 60%](images/weeklyosm-qr.png)

## What is weeklyOSM?

**OSM's Weekly News Source**
- For **everyone** - beginners to experts
- **15 languages** (translation volunteers essential)
- **Independent** from organizations/companies
- **52 issues per year** (no breaks!)

**https://weeklyosm.eu/**

---

## More Than Just News

![bg right:40% 80%](images/weeklyosm-taiwan.png)

- **Community spotlight**
- **Technical developments**
- **Local mapping projects**
- **Tool announcements**
- **Event coverage**
- **Educational content**

---

![](images/weeklyosm-taiwan.png)

---

<!-- Taiwan's Role -->

<!-- ![bg right:30% 50%](images/taiwan-osm-contributors.png)
-->

## Taiwan's Role in the Global Story

**Small Island, Big Impact**
- Active translation team
- Local community events → international news
- Bridge between APAC communities
- Night markets to global maps

---

### The Taiwan News

![bg right:40% 70%](images/taiwan-osm-example.png)

**Recent Taiwan Features:**
- Wikidata Taiwan collaboration in #763, #773
- Mapper interview in #665
- Community meetup

*From local knowledge to global audience*

---

<!-- Technical Deep Dive -->

## Behind the Curtain

![center 80%](images/news-collection.svg)


<!--
**News Collection Process**
- OSM diaries monitoring
- Community channels scanning
- Social media tracking
- Direct submissions
-->

---

## Meet OSMBC: 

### Our Secret Weapon

![bg right:40% 90%](images/osmbc-editor-image.png)

**OSMBC Tool Features:**
- Collaborative news collection
- Multi-language workflow
- Review system
- Publishing automation

**Live Demo Time!**

---

## **Live Demo Time!**

![](images/osmbc-editor-image.png)

---

### The Weekly Workflow

![center 90%](images/way-to-build-news.svg)

---

## My challenge

### productivity

**Professional Translation Process:**
- **OmegaT** (Computer-Aided Translation)
    - (New) AI-assisted translation
    - Terminology management
    - Translation memories
    - Dictionary lookups

---

### Translation Demo

<iframe src="https://player.vimeo.com/video/1054512440?badge=0&amp;autopause=0&amp;player_id=0&amp;app_id=58479" width="700" height="390" frameborder="0" allow="autoplay; fullscreen; picture-in-picture; clipboard-write; encrypted-media" title="OSMBC Demo"></iframe>


---

<!-- Team Structure -->

## weeklyOSM Team

![center 110%](images/team-structure.svg)

---

<!-- Challenges -->

## The Real Challenges

**Weekly Pressure Cooker:**

- 🕒 **Tight deadlines** - every single week
- 🌍 **Timezone coordination** across continents
- 👥 **Volunteer management** - life happens
- 🔄 **Consistent quality** with rotating contributors

---

## Community Challenges

**Human Factors:**
- **Contributor lifecycle** - onboarding to retention
- **Burnout prevention** - sustainable volunteering
- **Knowledge transfer** - preserving institutional memory
- **Quality vs. speed** - maintaining standards under pressure

---

<!-- Looking Forward -->

## The Future

**Technology Opportunities:**
- AI-assisted translation (with human oversight)
- Real-time collaboration improvements
- Mobile-first editing experience

**Community Growth:**
- More regional contributors
- Topic coverage from Asia
- Video/multimedia integration

---

## Lessons Learned

**What Works:**
✅ Consistent schedule builds habit
✅ Quality over quantity
✅ Community ownership
✅ Technical tools enable scale

---

## Lessons Learned

**What's Hard:**
❌ Sustainable volunteer engagement
❌ Priority for Life and editing
❌ Communication gaps

---

<!-- Call to Action -->

## Join the Story - Multiple Ways

**For Developers:**
- OSMBC tool improvements
- Tool to help Translation
- Mobile app development
- Analytics and metrics

---

## Join the Story

**For Writers:**
- Article writing and editing
- Community story coverage
- Translate to Chinese

---

## Join the Story

**For Taiwan Community:**
- 🇹🇼 **Translation team** - help with Traditional Chinese
- 📰 **Local news gathering** - share Taiwan OSM activities
- 🔧 **Technical contributions** - improve tools and processes
- 🎤 **Community liaison** - connect local and global stories

---

## Getting started

- Email: info@weeklyosm.eu
- Telegram: t.me/OSMTaiwan
- Facebook: https://www.facebook.com/openstreetmaptaiwan

---

## Resources and next

- 📰 weeklyOSM: https://weeklyosm.eu
- 🛠️ OSMBC: https://osmbc.openstreetmap.de
- Slides: miurahr.github.io/coscup2025-osmweekly

---

<!-- Closing -->
<!-- _class: gaia lead -->
<!-- _paginate: false -->
<!-- _header: '' -->
<!-- _footer: '' -->

# Every Week, Every Community, Every Mapper

## Thank You!
### Questions & Discussion

**Let's keep the OSM world connected**

---

<!-- Backup Slides -->

## Technical Stack Details

**Frontend:**
- Vue.js for reactive UI
- Bootstrap for responsive design
- Monaco Editor for code editing

**Backend:**
- Node.js with Express
- PostgreSQL for data storage
- Redis for caching

---

## Statistics

 - Translation Statistics after internationaled in 2014

| Language            | Contributors | Issues  | Start Date   |
|---------------------|--------------|---------|--------------|
| German              | 10           | 560+    | 2010(launch) |
| French              | 11           | 450+    | 2016.10      | 
| Japanese            | 6            | 560+    | 2014.10      |
| Traditional Chinese | 4            | 260+    | 2020.6       |

---

<script src="https://player.vimeo.com/api/player.js"></script>
<script type="module">
  import mermaid from 'https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.esm.min.mjs';
  mermaid.initialize({ startOnLoad: true });
</script>-