/* Perf pour un gros export:
 * - https://stackoverflow.com/a/56513729/1346819 (d'où ce .java)
 * - https://www.visualcron.com/forum.aspx?g=Posts&t=10019 (mentionne le C comme le + rapide; aussi qu'Oracle permet de créer de pseudo-tables en "ORGANIZATION EXTERNAL" qui pondent un CSV)
 */

/*
	[ -f opencsv.jar ] || curl -L -O https://github.com/hyee/OpenCSV/raw/master/release/opencsv.jar
	javac -cp opencsv.jar eu/outters/sqleur/SqlMinus.java
	jar cf sqlminus.jar eu/outters/sqleur/SqlMinus.class
 */

package eu.outters.sqleur;

import com.opencsv.CSVWriter;
import com.opencsv.ResultSetHelperService;

import java.sql.*;

public class SqlMinus
{
    public static void main(String[] args) throws Exception {
		/* Lecture des paramètres. */
		
		String conn = null;
		String[] auth;
		int posParam;
		char sepReq = '\n';
		
		for(posParam = -1; ++posParam < args.length;)
		{
			if(args[posParam].equals("-0"))
				sepReq = (char)0;
			else if(conn == null)
				// Le premier paramètre est la chaîne de connexion.
				conn = args[posParam];
			else
				// Le premier paramètre non standard est une requête.
				break;
		}
		
		if(conn == null)
			throw new Exception("La chaîne de connexion (premier paramètre) doit être de la forme id/mdp@machine:port:base");
		auth = conn.split("@", 2);
		if(auth.length < 2)
			throw new Exception("La chaîne de connexion (premier paramètre) doit être de la forme id/mdp@machine:port:base");
		conn = auth[1];
		auth = auth[0].split("/", 2);
		if(auth.length < 2)
			throw new Exception("La chaîne de connexion (premier paramètre) doit être de la forme id/mdp@machine:port:base");

    // write your code here
        //step1 load the driver class
        Class.forName("oracle.jdbc.driver.OracleDriver");

//step2 create  the connection object
        Connection con= DriverManager.getConnection("jdbc:oracle:thin:@"+conn, auth[0], auth[1]);

//step3 create the statement object
        Statement stmt=con.createStatement();

//step4 execute query
		
//        while(rs.next())
//            System.out.println(rs.getInt(1)+"  "+rs.getString(2)+"  "+rs.getString(3));

//step5 close the connection object


		/* À FAIRE: ne pas échapper les retours à la ligne si en mode séparateur \003 */
		/* À FAIRE: déguillemetter les noms de colonne */
		
		String fileName = null;
        boolean async = true;

		for(--posParam; ++posParam < args.length;)
		{
			if(args[posParam].equals("-o") && posParam < args.length - 1)
			{
				fileName = args[++posParam];
				if(fileName.equals("-"))
					fileName = null;
				continue;
			}
			
        try (CSVWriter writer = new CSVWriter(fileName)) {
				ResultSet rs = stmt.executeQuery(args[posParam]);
            //Define fetch size(default as 30000 rows), higher to be faster performance but takes more memory
            ResultSetHelperService.RESULT_FETCH_SIZE=50000;
            //Define MAX extract rows, -1 means unlimited.
            ResultSetHelperService.MAX_FETCH_ROWS=-1;
            writer.setAsyncMode(async);
            int result = writer.writeAll(rs, true);
            //return result - 1;
				if(fileName != null)
            System.out.println("Result: " + (result - 1));
			}
        }
        con.close();
    }
}
