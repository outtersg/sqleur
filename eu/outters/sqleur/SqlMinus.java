/* Perf pour un gros export:
 * - https://stackoverflow.com/a/56513729/1346819 (d'où ce .java)
 * - https://www.visualcron.com/forum.aspx?g=Posts&t=10019 (mentionne le C comme le + rapide; aussi qu'Oracle permet de créer de pseudo-tables en "ORGANIZATION EXTERNAL" qui pondent un CSV)
 */

/*
	[ -f opencsv.jar ] || curl -L -O https://github.com/hyee/OpenCSV/raw/master/release/opencsv.jar
	javac -cp opencsv.jar eu/outters/sqleur/SqlMinus.java
	jar cf sqlminus.jar eu/outters/sqleur/*.class
 */

package eu.outters.sqleur;

import com.opencsv.CSVParser;
import com.opencsv.CSVWriter;
import com.opencsv.ResultSetHelperService;

import java.io.IOException;
import java.sql.*;
import java.util.Scanner;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

public class SqlMinus
{
    public static void main(String[] args) throws Exception {
		new SqlMinus(args);
	}
	
	public Connection con;
	public String fileName = null;
	public int diag = -1;
	
	protected boolean avecNomsColonne = true;
	protected char sepCsv = ';';
	protected char guillemet = CSVParser.DEFAULT_QUOTE_CHARACTER;
	protected String invite = null;
	protected int delaiEntreSortiesStandard = 0;
	protected Pattern exprSpe = null;
	protected Pattern exprVide = null;
	
	public static String GRIS = "[90m";
	public static String VERT = "[32m";
	public static String JAUNE = "[33m";
	public static String ROUGE = "[31m";
	public static String BLANC = "[0m";
	
	public SqlMinus(String[] args) throws Exception
	{
		/* Initialisation. */
		
		exprSpe = Pattern.compile("^ *set +(?<p0>(?<head>hea(?:d(?:ing)?)?)) (?<v0>on|off) *$", Pattern.CASE_INSENSITIVE);
		exprVide = Pattern.compile("^(?:[ \t\r\n]+|--[^\n]*)*$");
		
		/* Lecture des paramètres. */
		
		String conn = null;
		String[] auth = null;
		int posParam;
		char sepReq = ';'; // À FAIRE: le "mode débile" SQL*Plus, où en cas de begin, on bascule le séparateur sur /
		boolean stdin = false;
		boolean sepCsvChoisi = false;
		
		for(posParam = -1; ++posParam < args.length;)
		{
			if(args[posParam].equals("-0"))
			{
				sepReq = (char)0;
				stdin = true;
			}
			else if(args[posParam].equals("-i"))
			{
				if(!sepCsvChoisi) sepCsv = '\t';
				if(diag < 0) diag = 0;
				if(invite == null) invite = "> ";
			}
			else if(args[posParam].equals("-s") || args[posParam].equals("-g"))
			{
				char sep = _paramSep(args[++posParam]);
				if(args[posParam - 1].equals("-s"))
				{
					sepCsv = sep;
				sepCsvChoisi = true;
				}
				else if(args[posParam - 1].equals("-g"))
					guillemet = sep;
			}
			else if(args[posParam].equals("--sans-entete"))
				avecNomsColonne = false;
			else if(args[posParam].equals("--ss"))
				// Séparation Sorties: millisecondes intercalées entre le retour sur une requête (stderr) et l'affichage du résultat (stdout).
				/* N.B.: À travers un SSH, rien n'y fait: OpenSSH privilégiant stdout sur stderr, lorsque l'on affiche la séquence suivante (entre parenthèses: moment de l'événement, puis sortie concernée):
				 *   (0 stderr) requête (6 stderr) ; -- durée ms
				 *   (6 stdout) résultat
				 *   (7 stdout) suite résultat
				 * C'est restitué en:
				 *   (0 stderr) requête (6 stdout) résultat
				 *   (6 stderr) ; -- durée ms
				 *   (7 stdout) suite résultat
				 */
				 delaiEntreSortiesStandard = Integer.parseInt(args[++posParam]);
			else if(args[posParam].equals("-"))
				stdin = true;
			else if(conn == null)
				// Le premier paramètre est la chaîne de connexion.
				conn = args[posParam];
			else
				// Le premier paramètre non standard est une requête.
				break;
		}
		if(diag < 0) diag = 3; /* À FAIRE?: selon que l'on est en -o ou non (car en -o le résultat part vers un fichier, donc besoin d'un retour, tandis que sans -o l'arrivée du résultat donne une indication d'où on en est). */
		
		if(conn != null)
		{
		auth = conn.split("@", 2);
		if(auth.length < 2)
				conn = null;
			else
			{
		conn = auth[1];
		auth = auth[0].split("/", 2);
		if(auth.length < 2)
				conn = null;
			}
		}
		if(conn == null)
			throw new Exception("La chaîne de connexion (premier paramètre) doit être de la forme id/mdp@machine:port:base");

    // write your code here
        //step1 load the driver class
        Class.forName("oracle.jdbc.driver.OracleDriver");
		con = DriverManager.getConnection("jdbc:oracle:thin:@"+conn, auth[0], auth[1]);

//step4 execute query
		
//        while(rs.next())
//            System.out.println(rs.getInt(1)+"  "+rs.getString(2)+"  "+rs.getString(3));

//step5 close the connection object


		/* À FAIRE: ne pas échapper les retours à la ligne si en mode séparateur \003 */
		
		for(--posParam; ++posParam < args.length;)
		{
			if(args[posParam].equals("-o") && posParam < args.length - 1)
			{
				fileName = args[++posParam];
				if(fileName.equals("-"))
					fileName = null;
				continue;
			}
			
			exec(args[posParam]);
        }
		
		if(stdin)
		{
			if(invite != null) { System.out.print(invite); System.out.flush(); }
			Scanner lecteur = new Scanner(System.in);
			if(sepReq == '\n')
				while(lecteur.hasNextLine())
					exec(lecteur.nextLine());
			else
			{
				lecteur.useDelimiter(""+sepReq);
				while(lecteur.hasNext())
					exec(lecteur.next());
			}
		}
		
		con.close();
    }
	
	protected char _paramSep(String param) throws Exception
	{
		if(param.charAt(0) == '\\' && param.length() > 1)
		{
			if(param.equals("\\t"))
				return '\t';
			else if(param.length() == 4)
			{
				int n = 0;
				char c;
				for(int i = 0; ++i <= 3;)
					if((c = param.charAt(i)) >= '0' && c <= '9')
						n = n * 8 + (c - '0');
					else
						throw new Exception("Séparateur non reconnu: "+param);
				return (char)n;
			}
			else
				throw new Exception("Séparateur non reconnu: "+param);
		}
		else if(param.length() == 1)
			return param.charAt(0);
		else
			throw new Exception("Séparateur non reconnu: "+param);
	}
	
	public void exec(String req) throws SQLException, IOException, Exception
	{
		Matcher vide = exprVide.matcher(req);
		if(vide.find()) return;
		
		Matcher spe = exprSpe.matcher(req);
		if(spe.find())
		{
			String param = null;
			if(spe.group("head") != null)
				avecNomsColonne = spe.group("v0").equals("on");
			if(invite != null) { System.out.print(invite); System.out.flush(); }
			return;
		}
		
		if(diag >= 2)
		System.err.print(GRIS+req.trim()+BLANC+" ");
		
		try
		{
		Statement stmt = con.createStatement();
			ResultSet rs = null;
		
		// À FAIRE: si fileName == null (stdout), inutile de créer un nouveau CSVWriter?
			try
			(
				CSVWriter writer =
					diag > 0
					? new EcrivainVerbeux(fileName, sepCsv, guillemet, this)
					: new CSVWriter(fileName, sepCsv, guillemet, guillemet, CSVWriter.DEFAULT_LINE_END)
			)
			{
				// Merci https://datubaze.files.wordpress.com/2015/11/r_menon_expert_ora_jdbc_programming_2005_gram.pdf pour comment jouer aussi bien du select que du DDL!
				boolean estUneReq = stmt.execute(req);
				if(estUneReq)
				{
					rs = stmt.getResultSet();
            //Define fetch size(default as 30000 rows), higher to be faster performance but takes more memory
            ResultSetHelperService.RESULT_FETCH_SIZE=50000;
            //Define MAX extract rows, -1 means unlimited.
            ResultSetHelperService.MAX_FETCH_ROWS=-1;
				writer.setAsyncMode(fileName != null);
            int result = writer.writeAll(rs, avecNomsColonne);
            //return result - 1;
				if(fileName != null)
            System.out.println("Result: " + (result - 1));
				}
				else
				{
					// À FAIRE: exploiter stmt.getUpdateCount()?
					//int n = stmt.getUpdateCount();
					writer.writeNext(null);
				}
			}
			finally
			{
				if(rs != null) rs.close();
				if(stmt != null) stmt.close();
			}
		}
		catch(Exception ex)
		{
			notif(-1, -1);
			if(invite != null)
				diag(ROUGE+ex.getMessage()+BLANC);
			else
			throw ex;
		}
		
		if(invite != null) { System.out.print(invite); System.out.flush(); }
	}
	
	public void notif(int nLignes, double durée)
	{
		if(nLignes <= 0)
		{
			long t = Math.round(durée);
			String coul = t < 0 ? ROUGE : (t < 1000 ? VERT : (t < 10000 ? JAUNE : ROUGE));
			String tchaîne = t < 0 ? "ERR" : (t < 1000 ? t+" ms" : (t < 10000 ? (t / 1000)+" s" : (t < 59500 ? Math.round(t / 1000.0)+" s" : (t / 60000)+" mn"+(t % 60000 > 0 ? " "+((t / 1000) % 60)+" s" : ""))));
			diag(GRIS+"; "+coul+"-- ["+tchaîne+"]"+BLANC);
		}
	}
	
	protected void diag(String message)
	{
		System.err.println(message);
		System.err.flush();
		if(delaiEntreSortiesStandard > 0)
			try { Thread.sleep(delaiEntreSortiesStandard); } catch(Exception ex) {}
	}
}

class EcrivainVerbeux extends CSVWriter
{
	protected SqlMinus appelant;
	protected int pos = -9999; // En deçà du premier numéro de ligne qu'on peut recevoir (-1 pour une erreur, 0 pour un déroulé normal).
	protected double t0;
	
	public EcrivainVerbeux(String chemin, char sep, char guillemet, SqlMinus appelant) throws IOException
	{
		super(chemin, sep, guillemet, guillemet, CSVWriter.DEFAULT_LINE_END);
		this.appelant = appelant;
		t0 = System.currentTimeMillis();
	}
	
	public EcrivainVerbeux(String chemin, SqlMinus appelant) throws IOException
	{
		super(chemin);
		this.appelant = appelant;
		t0 = System.currentTimeMillis();
	}
	
	protected void writeLog(int nLignes)
	{
		// On ne notifie que si une progression a eu lieu.
		// En effet on peut avoir double notification de la ligne 0:
		// - par le CSVWriter en cas de masquage des en-têtes, car alors il force un appel pour nous signaler l'entrée en résultats (d'habitude calée sur l'obtention de la ligne d'en-têtes)
		// - par SqlMinus qui nous invoquer un dernier writeNext() pour être sûr qu'on a toute l'info.
		if(nLignes > this.pos)
		{
		appelant.notif(nLignes, System.currentTimeMillis() - t0);
			this.pos = nLignes;
		}
		super.writeLog(nLignes);
	}
}
