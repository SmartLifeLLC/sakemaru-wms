発注用のinit seederを作る。
ContractorInitSeeder

admin/contractors/1/edit


こちらの基本時刻をいれる
- 自動発注生成時刻 = 09:30
- 送信時刻 = 10:30



ただし、送信方式がJX-FINETの場合
- 自動発注生成時刻 = 11:00
- 送信時刻 = 12:00

生成後InitSystemSeederにSeederを追加
