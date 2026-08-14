<?php

namespace App\Support;

class GenderFromFirstName
{
    /**
     * Infer Male/Female from a full name using the first given name.
     * Returns null when uncertain.
     */
    public function infer(?string $fullName): ?string
    {
        $first = $this->firstName($fullName);
        if ($first === null) {
            return null;
        }

        if (isset(self::FEMALE[$first])) {
            return 'Female';
        }
        if (isset(self::MALE[$first])) {
            return 'Male';
        }

        if (str_contains($first, '-')) {
            foreach (explode('-', $first) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                if (isset(self::FEMALE[$part])) {
                    return 'Female';
                }
                if (isset(self::MALE[$part])) {
                    return 'Male';
                }
            }
        }

        return null;
    }

    public function firstName(?string $fullName): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $fullName) ?? '');
        if ($name === '') {
            return null;
        }

        $parts = preg_split('/\s+/u', $name) ?: [];
        $skip = [
            'mr' => true, 'mrs' => true, 'miss' => true, 'ms' => true, 'dr' => true,
            'eng' => true, 'hon' => true, 'rev' => true, 'prof' => true, 'sir' => true,
        ];

        foreach ($parts as $part) {
            $clean = strtolower(trim($part, " \t\n\r\0\x0B.,"));
            $clean = preg_replace("/[^a-z'\\-]/", '', $clean) ?? '';
            if ($clean === '' || isset($skip[$clean]) || strlen($clean) < 2) {
                continue;
            }
            if (str_contains($clean, 'gmail') || str_contains($clean, 'yahoo') || str_contains($clean, 'hotmail')) {
                continue;
            }

            return $clean;
        }

        return null;
    }

    /** @var array<string, true> */
    private const FEMALE = [
        'agnes' => true, 'alice' => true, 'alisha' => true, 'amanda' => true, 'amber' => true,
        'amelia' => true, 'amy' => true, 'angela' => true, 'anita' => true, 'ann' => true,
        'anna' => true, 'anne' => true, 'annette' => true, 'annmarion' => true, 'anyango' => true,
        'apiyo' => true, 'achieng' => true, 'adhiambo' => true, 'akinyi' => true, 'atieno' => true,
        'auma' => true, 'awino' => true, 'beatrice' => true, 'betty' => true, 'beverly' => true,
        'bosibori' => true, 'brenda' => true, 'bridget' => true, 'bridgit' => true, 'caroline' => true,
        'carol' => true, 'carolyne' => true, 'cate' => true, 'catherine' => true, 'cecilia' => true,
        'charity' => true, 'charlotte' => true, 'chebet' => true, 'chelagat' => true, 'chelsy' => true,
        'chemutai' => true, 'chepkoech' => true, 'chepngetich' => true, 'cherono' => true,
        'christine' => true, 'christina' => true, 'cindy' => true, 'clara' => true, 'clare' => true,
        'cynthia' => true, 'daisy' => true, 'deborah' => true, 'diana' => true, 'dinah' => true,
        'dorcas' => true, 'doris' => true, 'dorris' => true, 'dorothy' => true, 'edith' => true,
        'elizabeth' => true, 'ellen' => true, 'emily' => true, 'emma' => true, 'esther' => true,
        'eunice' => true, 'eva' => true, 'eve' => true, 'faith' => true, 'felicity' => true,
        'felista' => true, 'felisters' => true, 'fidelity' => true, 'fiona' => true, 'florence' => true,
        'frida' => true, 'fridah' => true, 'gathoni' => true, 'gladys' => true, 'gloria' => true,
        'grace' => true, 'hannah' => true, 'helen' => true, 'hope' => true, 'immaculate' => true,
        'irene' => true, 'ivy' => true, 'jacinta' => true, 'jackie' => true, 'jackline' => true,
        'jane' => true, 'janet' => true, 'jennifer' => true, 'jenny' => true, 'jepchirchir' => true,
        'jelagat' => true, 'jerop' => true, 'jessica' => true, 'jessy' => true, 'jill' => true,
        'joan' => true, 'joanna' => true, 'josephine' => true, 'joy' => true, 'joyce' => true,
        'judith' => true, 'judy' => true, 'julia' => true, 'juliana' => true, 'juliet' => true,
        'karen' => true, 'kate' => true, 'kathy' => true, 'kemunto' => true, 'kerubo' => true,
        'kwamboka' => true, 'laura' => true, 'leah' => true, 'lilian' => true, 'lillian' => true,
        'lily' => true, 'linda' => true, 'lisa' => true, 'lois' => true, 'loice' => true,
        'lorna' => true, 'loyce' => true, 'lucy' => true, 'lydia' => true, 'lynn' => true,
        'maggie' => true, 'makena' => true, 'margaret' => true, 'maria' => true, 'marie' => true,
        'marion' => true, 'martha' => true, 'mary' => true, 'maureen' => true, 'meg' => true,
        'melanie' => true, 'melissa' => true, 'mercy' => true, 'michelle' => true, 'mildred' => true,
        'milka' => true, 'milkah' => true, 'millicent' => true, 'miriam' => true, 'monica' => true,
        'moraa' => true, 'muthoni' => true, 'nafula' => true, 'naliaka' => true, 'nancy' => true,
        'nana' => true, 'naomi' => true, 'nasimiyu' => true, 'natalie' => true, 'nekesa' => true,
        'nelly' => true, 'nerea' => true, 'njeri' => true, 'nyambura' => true, 'nyawira' => true,
        'olivia' => true, 'pamela' => true, 'patricia' => true, 'pauline' => true, 'peris' => true,
        'phoebe' => true, 'phyllis' => true, 'prisca' => true, 'priscilla' => true, 'purity' => true,
        'rachael' => true, 'racheal' => true, 'rachel' => true, 'rahel' => true, 'rebecca' => true,
        'regina' => true, 'rhoda' => true, 'rita' => true, 'rose' => true, 'roseline' => true,
        'roselyn' => true, 'rosemary' => true, 'ruth' => true, 'sally' => true, 'salome' => true,
        'sandra' => true, 'sarah' => true, 'selina' => true, 'serah' => true, 'sharon' => true,
        'sheila' => true, 'shirley' => true, 'sophia' => true, 'sophie' => true, 'stacey' => true,
        'stacy' => true, 'stephanie' => true, 'stella' => true, 'susan' => true, 'sylvia' => true,
        'tabitha' => true, 'teresa' => true, 'tinah' => true, 'tracy' => true, 'vanessa' => true,
        'veronica' => true, 'viola' => true, 'violet' => true, 'virginia' => true, 'victoria' => true,
        'vivian' => true, 'wairimu' => true, 'waithera' => true, 'wambui' => true, 'wangari' => true,
        'wanjeri' => true, 'wanjiku' => true, 'wanjira' => true, 'winnie' => true, 'winny' => true,
        'yvonne' => true, 'zawadi' => true, 'zipporah' => true, 'zippy' => true,
        'audrey' => true, 'beccah' => true, 'benta' => true, 'bernice' => true, 'beth' => true,
        'camilla' => true, 'cyndy' => true, 'eglay' => true, 'everlyn' => true, 'florah' => true,
        'fredah' => true, 'hellen' => true, 'joyse' => true, 'milcah' => true, 'nahida' => true,
        'valentine' => true, 'vicky' => true, 'wahito' => true, 'zarina' => true, 'annes' => true,
        'gaye' => true, 'minoo' => true, 'voborah' => true,
    ];

    /** @var array<string, true> */
    private const MALE = [
        'aaron' => true, 'abdi' => true, 'abdullahi' => true, 'abiud' => true, 'abraham' => true,
        'adam' => true, 'adrian' => true, 'albert' => true, 'alex' => true, 'alexander' => true,
        'alfred' => true, 'allan' => true, 'allen' => true, 'amos' => true, 'andrew' => true,
        'anthony' => true, 'anton' => true, 'arnold' => true, 'arthur' => true, 'austin' => true,
        'barasa' => true, 'barry' => true, 'ben' => true, 'benard' => true, 'benedict' => true,
        'benjamin' => true, 'bernard' => true, 'billy' => true, 'boaz' => true, 'bob' => true,
        'boniface' => true, 'brian' => true, 'bruce' => true, 'bryan' => true, 'caleb' => true,
        'calvin' => true, 'carl' => true, 'charles' => true, 'cheruiyot' => true, 'chris' => true,
        'christian' => true, 'christopher' => true, 'cliff' => true, 'clinton' => true, 'collins' => true,
        'cyrus' => true, 'dan' => true, 'daniel' => true, 'danny' => true, 'dave' => true,
        'david' => true, 'davis' => true, 'denis' => true, 'dennis' => true, 'derek' => true,
        'derrick' => true, 'dickson' => true, 'dominic' => true, 'donald' => true, 'douglas' => true,
        'duncan' => true, 'edward' => true, 'edwin' => true, 'eliud' => true, 'elijah' => true,
        'elisha' => true, 'elliot' => true, 'elvis' => true, 'emanuel' => true, 'emmanuel' => true,
        'eric' => true, 'erick' => true, 'ernest' => true, 'eugene' => true, 'evans' => true,
        'ezekiel' => true, 'ezra' => true, 'felix' => true, 'fidel' => true, 'francis' => true,
        'frank' => true, 'franklin' => true, 'fred' => true, 'frederick' => true, 'fredrick' => true,
        'gabriel' => true, 'geoffrey' => true, 'george' => true, 'gerald' => true, 'gibson' => true,
        'gilbert' => true, 'gitau' => true, 'godfrey' => true, 'gordon' => true, 'graham' => true,
        'gregory' => true, 'harrison' => true, 'harry' => true, 'harvey' => true, 'henry' => true,
        'hezekiah' => true, 'hilary' => true, 'hillary' => true, 'howard' => true, 'hudson' => true,
        'ian' => true, 'ibrahim' => true, 'isaac' => true, 'isack' => true, 'isaiah' => true,
        'ismael' => true, 'ismail' => true, 'ivan' => true, 'jack' => true, 'jacob' => true,
        'jairus' => true, 'james' => true, 'japheth' => true, 'jared' => true, 'jason' => true,
        'jeff' => true, 'jeffrey' => true, 'jeremy' => true, 'jeremiah' => true, 'jesse' => true,
        'jim' => true, 'jimmy' => true, 'joash' => true, 'job' => true, 'joe' => true,
        'joel' => true, 'john' => true, 'johnny' => true, 'jonah' => true, 'jonathan' => true,
        'joseph' => true, 'joshua' => true, 'josiah' => true, 'julian' => true, 'julius' => true,
        'justin' => true, 'kamau' => true, 'karanja' => true, 'kariuki' => true, 'keith' => true,
        'kennedy' => true, 'kenneth' => true, 'kelvin' => true, 'kevin' => true, 'kimani' => true,
        'kipchoge' => true, 'kipkorir' => true, 'kiplagat' => true, 'kiprono' => true, 'kiptalam' => true,
        'kiptoo' => true, 'langat' => true, 'lawrence' => true, 'leonard' => true, 'levy' => true,
        'lewis' => true, 'louis' => true, 'lucas' => true, 'luke' => true, 'lwande' => true,
        'macharia' => true, 'maina' => true, 'manuel' => true, 'marcus' => true, 'mark' => true,
        'martin' => true, 'marvin' => true, 'mathew' => true, 'matthew' => true, 'maurice' => true,
        'max' => true, 'maxwell' => true, 'meshack' => true, 'michael' => true, 'mike' => true,
        'mohamed' => true, 'mohammed' => true, 'morgan' => true, 'morris' => true, 'moses' => true,
        'muhammad' => true, 'musyoka' => true, 'mutiso' => true, 'mutua' => true, 'mwangi' => true,
        'mwenda' => true, 'nathan' => true, 'nelson' => true, 'nicholas' => true, 'nick' => true,
        'nixon' => true, 'njoroge' => true, 'noah' => true, 'norman' => true, 'ochieng' => true,
        'odhiambo' => true, 'okoth' => true, 'oliver' => true, 'omar' => true, 'omondi' => true,
        'oscar' => true, 'otieno' => true, 'owino' => true, 'patrick' => true, 'paul' => true,
        'peter' => true, 'philip' => true, 'phillip' => true, 'raphael' => true, 'rashid' => true,
        'raymond' => true, 'reuben' => true, 'richard' => true, 'robert' => true, 'robin' => true,
        'rodgers' => true, 'roger' => true, 'ronald' => true, 'rotich' => true, 'ryan' => true,
        'sammy' => true, 'samuel' => true, 'samwel' => true, 'schnider' => true, 'scott' => true,
        'sean' => true, 'sebastian' => true, 'shadrack' => true, 'sheldon' => true, 'sidney' => true,
        'sila' => true, 'silas' => true, 'simiyu' => true, 'simon' => true, 'solomon' => true,
        'sospeter' => true, 'stanley' => true, 'stephen' => true, 'steven' => true, 'stuart' => true,
        'teddy' => true, 'terry' => true, 'thomas' => true, 'timothy' => true, 'titus' => true,
        'tom' => true, 'tony' => true, 'victor' => true, 'vincent' => true, 'vinnie' => true,
        'wafula' => true, 'wallace' => true, 'walter' => true, 'wekesa' => true, 'wesley' => true,
        'william' => true, 'wilson' => true, 'wycliffe' => true, 'yusuf' => true, 'zachariah' => true,
        'zachary' => true, 'zack' => true,
        'bonface' => true, 'davyd' => true, 'elias' => true, 'mesh' => true, 'musa' => true,
        'mutegi' => true, 'mwai' => true, 'mwangu' => true, 'naboth' => true, 'onunda' => true,
        'situma' => true, 'suleiman' => true, 'waiyaki' => true,
    ];
}
